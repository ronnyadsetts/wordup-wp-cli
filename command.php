<?php

class Wordup_tools {

    protected $package_types = array('themes', 'plugins');

    public static function get_project_dirname($wp_package){
        if($wp_package['type'] === 'plugins'){
            return dirname($wp_package['slug']);
        }
        return $wp_package['slug'];
    }

    public static function is_dir_empty($dir){
        if(!is_readable($dir)) return NULL;
        return (count(scandir($dir)) == 2);
    }

    public static function wp_package_path_exists($wp_package){
        $project_path = '/var/www/html/wp-content/'.$wp_package['type'].'/'.self::get_project_dirname($wp_package);
        return file_exists($project_path);
    }

    public static function connect_src_with_wp($wp_package){
        //Connect src with wordpress
        WP_CLI::log('Connect your '.$wp_package['type'].' source code with WordPress');

        $wp_path = '/var/www/html/wp-content/'.$wp_package['type'];
        WP_CLI::launch('ln -s /src '.$wp_path.'/'.self::get_project_dirname($wp_package));
        
        //Activate Plugin
        if( $wp_package['type'] === 'plugins' && is_file($wp_path.'/'.$wp_package['slug'])){
            WP_CLI::runcommand('plugin activate '.$wp_package['slug']);
        }
    }

    public static function create_wordup_archive($version, $files){
        $data = array(
            'type'=>'wordup',
            'wp_version'=> $version,
            'files'=> $files,
            'created'=>time(),
            'api_version'=>'1.0'
        );
        return $data;
    }

    public static function extract_remote_zip_to_wp_content($url,$name, $type){

        $dest = "/var/www/html/wp-content/$type/$name";
        $tmp = "/tmp/$name/extracted";

        WP_CLI::launch("mkdir /tmp/$name $dest");
        WP_CLI::launch("mkdir $tmp");

        WP_CLI::log('Download file from '.$url);
        WP_CLI::launch('curl -L "'.$url.'" > /tmp/'.$name.'/src.file');
        $mime_type = mime_content_type("/tmp/$name/src.file");

        if($mime_type == 'application/zip'){
            WP_CLI::launch('unzip /tmp/'.$name.'/src.file -d '.$tmp);
        }else if($mime_type == 'application/gzip' || $mime_type == 'application/tar+gzip' || $mime_type == 'application/x-gzip'){
            WP_CLI::launch('tar -xvf /tmp/'.$name.'/src.file -C '.$tmp);
        }

        $extracted_files = scandir($tmp);
        if(count($extracted_files) === 3 && is_dir($tmp.'/'.$extracted_files[2])) {
            //There is only one dir with the content in it
            WP_CLI::log("There was only one subfolder detected for $name -> Move extracted files for");
            WP_CLI::launch('mv "'.$tmp.'"/*/* "'.$dest.'"');
        }else if(count($extracted_files) > 3){
            WP_CLI::launch('mv "'.$tmp.'"/* "'.$dest.'"');
        }

        WP_CLI::launch('rm -r /tmp/'.$name);
    }

    public static function get_signed_url($url , $route, $private_key){
        $expires =  time() + 5;
        $string_to_sign = $route.$expires;
        $sig =  base64_encode(hash_hmac('sha256', $string_to_sign, $private_key, TRUE));
        return $url.'?rest_route='.$route.'&signature='.rawurlencode($sig).'&expires='.$expires;
    }
}


class Wordup_Commands {

    public $wp_package;
    public $server = 'http://localhost';
    public $server_port = '8000';
    public $site_url = FALSE;
    public $scaffold = FALSE;

    public $wp_path = '';
    public $tmp_path = '';

    function __construct() {
        //Set global paramaters more convenient
        $this->tmp_path = WP_CLI\Utils\trailingslashit( WP_CLI\Utils\get_temp_dir() );

        if ( ! defined( 'ABSPATH' ) ) {
            WP_CLI::error( "Wordup could not find WordPress root path");
        }
        $this->wp_path = WP_CLI\Utils\trailingslashit( ABSPATH );
    }

    /**
     * Installs the base WordPress dev stack
     *
     * ## OPTIONS
     * 
     * <config>
     * : A base64 encoded json string, with the wordup package.json config 
     * 
     * [--wordup-connect=<wordup-connect>]
     * : A url to an wordpress hosted website, with the wordup-connect plugin installed an running
     * 
     * [--wordup-archive=<wordup-archive>]
     * : A local file or an url with a wordup generated archive to get the source/content files from 
     * 
     * [--private-key=<private-key>]
     * : An optional private key 
     * 
     * [--scaffold]
     * : Scaffold project src data
     * 
     * [--siteurl=<siteurl>]
     * : A custom siteurl for the WordPress installation
     * 
     * ## EXAMPLES
     *
     *     wp wordup install base64configstring
     *
     * @when before_wp_load
     */
    public function install( $args, $assoc_args ) {
        list( $config ) = $args;

        $this->parse_config($config);

        //Set port
        $this->server_port = getenv("WORDUP_PORT");

        //Set scaffold
        $this->scaffold = $assoc_args['scaffold'];

        //Set site_url
        $this->site_url = $assoc_args['siteurl'];

        //Install types
        $wordup_connect = $assoc_args['wordup-connect'];
        $wordup_archive = $assoc_args['wordup-archive'];

        if(!empty($wordup_connect)){
            $this->install_from_wordup_connect($wordup_connect, $assoc_args['private-key']);
        }else if(!empty($wordup_archive)){
            $this->install_from_archive($wordup_archive);
        }else{
            $this->install_from_scratch();
        }

        Wordup_tools::connect_src_with_wp($this->wp_package);

        WP_CLI::success( "WordPress development stack successfully installed under $this->server:$this->server_port" );
    }

    /**
     * Export /src or whole installation to a zip
     *
     * ## OPTIONS
     * 
     * <config>
     * : A base64 encoded json string, with the wordup package.json config 
     *
     * [--type=<type>]
     * : What do you want to export
     * ---
     * default: src
     * options:
     *   - src
     *   - installation
     *   - sql
     * ---
     * 
     * [--filename=<filename>]
     * : An optional filename
     *
     * ## EXAMPLES
     *
     *     wp wordup export base64configstring 
     *
     * @when after_wp_load
     */
    public function export( $args, $assoc_args ){
        list( $config ) = $args;
        $this->parse_config($config);

        $export_type = $assoc_args['type'];

        $project_folder_name = Wordup_tools::get_project_dirname($this->wp_package);

        //Create tmp folder 
        $export_tmp = '/tmp/wordup-export/';
        if($export_type === 'src' || $export_type === 'installation'){
            if (file_exists('/tmp/wordup-export/')) {
                WP_CLI::launch('rm -r /tmp/wordup-export/');
            }
            mkdir('/tmp/wordup-export');
        }

        //Export Theme/plugin src
        if($export_type === 'src'){

            //If there is no .distignore create it 
            if(!is_file('/src/.distignore')){
                file_put_contents('/src/.distignore', implode("\n",array('.distignore','.git','.gitignore','node_modules')));
            }

            $export_version = FALSE;
            if($this->wp_package['type'] === 'themes'){
                $export_version = WP_CLI::runcommand('theme get '.$this->wp_package['slug'].' --field=version', array('return' => true));
            }else if($this->wp_package['type'] === 'plugins'){
                $export_version = WP_CLI::runcommand('plugin get '.$this->wp_package['slug'].' --field=version', array('return' => true));
            }

            if($export_version){
                $final_zip = $project_folder_name.'-'.$export_version.'.zip';

                WP_CLI::runcommand('dist-archive /src '.$export_tmp.'src.zip --format=zip');
                WP_CLI::launch('unzip '.$export_tmp.'src.zip -d '.$export_tmp);
                WP_CLI::launch('mv '.$export_tmp.'src '.$export_tmp.$project_folder_name);
                WP_CLI::launch('cd '.$export_tmp.' && zip -r '.$final_zip.' '.$project_folder_name);
                WP_CLI::launch('mv '.$export_tmp.$final_zip.' /dist/'.$final_zip);
                WP_CLI::log('Move src.zip -> dist/'.$final_zip);
                WP_CLI::launch('rm -r '.$export_tmp);

            }else{
                WP_CLI::error( "Could not read version of the exported project");
            }
        }

        //Export sql
        if($export_type === 'sql'){

            WP_CLI::runcommand('db export /dist/db-snapshot-'.time().'.sql');

        }

        //Export Installation
        if($export_type === 'installation'){

            $project_tmp_path = $export_tmp.'wp-content/'.$this->wp_package['type'].'/'.$project_folder_name;

            WP_CLI::launch('cp -a /var/www/html/. '.$export_tmp);

            //Export Sql
            $sql_file = 'db-snapshot-'.time().'.sql';
            WP_CLI::runcommand('db export '.$export_tmp.$sql_file);

            //Create wordup-archive 
            $wp_version = WP_CLI::runcommand('core version', array('return'=>true));
            $wordup_archive_params= Wordup_tools::create_wordup_archive($wp_version, array('db'=>$sql_file));
            file_put_contents($export_tmp.'wordup-archive.json', json_encode($wordup_archive_params, JSON_PRETTY_PRINT));
            WP_CLI::log('Write wordup-archive.json for WP-Version: '.$wp_version);

            //---- Export src to wp-content with dist-archive  ----
            unlink($project_tmp_path);  //Unlink symlink

            WP_CLI::runcommand('dist-archive /src '.$export_tmp.'src.zip --format=zip', array('return'=>true));
            WP_CLI::launch('unzip '.$export_tmp.'src.zip -d '.$export_tmp);
            WP_CLI::launch('mv '.$export_tmp.'src '.$project_tmp_path);
            unlink($export_tmp.'src.zip');

            $filename = !empty($assoc_args['filename']) ? $assoc_args['filename'] : 'installation-'.date('Y-m-d');

            //Create archive
            WP_CLI::launch('cd '.$export_tmp.' && tar czf /dist/'. $filename.'.tar.gz .');
            WP_CLI::launch('rm -r '.$export_tmp);
        }

        WP_CLI::success( "Successfully exported $export_type" );
    }

    private function parse_config($config){
        $config_json = json_decode(base64_decode($config), true); 
        if(!is_array($config_json)){
            WP_CLI::error( "Could not parse wordup package.json settings" );
        }else if(empty($config_json['slug']) || empty($config_json['type'])){
            WP_CLI::error( "Could not find wordup settings in package.json" );
        }else{
            WP_CLI::log( "Parsed wordup package.json with slug: ".$config_json['slug'] );
        }
        $this->wp_package  = $config_json;
    }

    private function install_from_scratch() {
        $installation_config = $this->wp_package['wpInstall'];

        //Install basic stuff
        WP_CLI::runcommand('core install  \
                    --path="/var/www/html" \
                    --url="'.(!empty($this->site_url) ? $this->site_url : $this->server.':'.$this->server_port).'" \
                    --title="'.$installation_config['title'].'" \
                    --admin_user="'.$installation_config['adminUser'].'" \
                    --admin_password="'.$installation_config['adminPassword'].'" \
                    --admin_email="'.$installation_config['adminEmail'].'" \
                    --skip-email');
        
        //Because its a development context, we set debug to true
        WP_CLI::runcommand("config set WP_DEBUG true --raw");

        // Only set the flexible port siteurl config, if there is no custom site_url
        if(empty($this->site_url)){
            WP_CLI::runcommand("config set WP_HOME \'".$this->server.":\'.getenv\(\'WORDUP_PORT\'\) --raw");
            WP_CLI::runcommand("config set WP_SITEURL \'".$this->server.":\'.getenv\(\'WORDUP_PORT\'\) --raw");
        }else{
            WP_CLI::runcommand("config set WP_HOME ".$this->site_url);
            WP_CLI::runcommand("config set WP_SITEURL ".$this->site_url);
            //This is kind of an hack. Because wordpress is doing crazy stuff with redirects
            WP_CLI::runcommand("config set _SERVER[\'HTTP_HOST\']  \'".parse_url($this->site_url, PHP_URL_HOST)."\' --raw --type=variable");
        }

        // ------ Install Plugins -----------
        if(isset($installation_config['plugins']) && is_array($installation_config['plugins'])){
            foreach ($installation_config['plugins'] as $key => $value) {
                if (filter_var($value, FILTER_VALIDATE_URL)) {
                    Wordup_tools::extract_remote_zip_to_wp_content($value, $key, 'plugins');
                    WP_CLI::runcommand('plugin activate '.$key);
                }else{
                    WP_CLI::runcommand('plugin install '.$key.' --activate');
                }
            }
        }

        // ------ Install Themes -----------
        if(isset($installation_config['themes']) && is_array($installation_config['themes'])){
            foreach ($installation_config['themes'] as $key => $value) {
                if (filter_var($value, FILTER_VALIDATE_URL)) {
                    Wordup_tools::extract_remote_zip_to_wp_content($value, $key, 'themes');
                }else{
                    WP_CLI::runcommand('theme install '.$key);
                }
            }
        }

        $this->scaffold_src();
    }

    private function install_from_archive($path) {

        WP_CLI::launch('mkdir /tmp/wparchive');

        if (filter_var($path, FILTER_VALIDATE_URL)){
            WP_CLI::launch('curl -L "'.$path.'" > /tmp/archive.file');
            WP_CLI::launch('tar -xvf /tmp/archive.file -C /tmp/wparchive');
            WP_CLI::launch('unlink /tmp/archive.file');
        }else {
            WP_CLI::launch('tar -xvf /source/'.$path.' -C /tmp/wparchive');
        }

        WP_CLI::launch('rm -r /var/www/html/*');
        WP_CLI::launch('mv /tmp/wparchive/* /var/www/html/');
        WP_CLI::launch('rm -rf /tmp/wparchive');

        //Read wordup-archive file
        $wordup_archive = json_decode(file_get_contents('/var/www/html/wordup-archive.json'), true);
        if(!$wordup_archive['files']){
            WP_CLI::error( "Could not find wordup-archive.json file");
        }
        $sql_dump_path = $wordup_archive['files']['db'];
        

        WP_CLI::runcommand('config set DB_NAME wordpress');
        WP_CLI::runcommand('config set DB_USER wordpress');
        WP_CLI::runcommand('config set DB_PASSWORD wordpress');
        WP_CLI::runcommand('config set DB_HOST db:3306');
        //Because its a development context, we set debug to true
        WP_CLI::runcommand("config set WP_DEBUG true --raw");

        if(empty($this->site_url)){
            WP_CLI::runcommand("config set WP_HOME \'".$this->server.":\'.getenv\(\'WORDUP_PORT\'\) --raw");
            WP_CLI::runcommand("config set WP_SITEURL \'".$this->server.":\'.getenv\(\'WORDUP_PORT\'\) --raw");
        
        }else{
            WP_CLI::runcommand("config set WP_HOME ".$this->site_url);
            WP_CLI::runcommand("config set WP_SITEURL ".$this->site_url);
            WP_CLI::runcommand("config set _SERVER[\'HTTP_HOST\']  \'".parse_url($this->site_url, PHP_URL_HOST)."\' --raw --type=variable");
        }
        WP_CLI::runcommand('db import '.$sql_dump_path);
        
        WP_CLI::runcommand('rewrite flush');

        //Delete Sql File
        WP_CLI::launch('unlink '.$sql_dump_path);

        //Set .htaccess to a default wordpress
        $htaccess = "# BEGIN WordPress\n
                <IfModule mod_rewrite.c>\n
                RewriteEngine On\n
                RewriteBase /\n
                RewriteRule ^index\.php$ - [L]\n
                RewriteCond %{REQUEST_FILENAME} !-f\n
                RewriteCond %{REQUEST_FILENAME} !-d\n
                RewriteRule . /index.php [L]\n
                </IfModule>\n
                # END WordPress";
        file_put_contents('/var/www/html/.htaccess', $htaccess);

        $this->delete_installed_project();
        $this->scaffold_src();

    }


    private function install_from_wordup_connect($url, $private_key) {
        if(empty($private_key)){
            WP_CLI::error( "Please provide a private key");
        }

        $supported_types = array('wordup', 'updraft');

        //Make 
        $resp = file_get_contents(Wordup_tools::get_signed_url($url, '/wordup/v1/dl/', $private_key));
        if(!$resp){
            WP_CLI::error( "Could not access connected WordPress website");
        }

        $resp_array = json_decode($resp, TRUE);

        if($resp_array['status'] !== 'ok'){
            WP_CLI::error( "There was a problem with the returned data from the server");
        }

        $wordup_connect = $resp_array['data'];

        if($wordup_connect['type'] === 'updraft'){

            $wp_version = $wordup_connect['wp_version'];
            $locale = $wordup_connect['locale'];

            WP_CLI::launch('rm -r /var/www/html/*');
            WP_CLI::runcommand('core download --skip-content --version='.$wp_version.' --locale='.$locale);

            WP_CLI::launch('mkdir /var/www/html/wp-content');

            $files = $wordup_connect['files']; 
            
            $tmp_folder = "/tmp/".$wordup_connect['folder'];

            WP_CLI::launch("mkdir $tmp_folder");
            foreach($files as $key => $all_urls){
                $tmp_urls = (!is_array($all_urls) ? array($all_urls) : $all_urls);
                foreach($tmp_urls as $file_url){
                    $url_parts = explode('/', $file_url);
                    $file_name = $url_parts[count($url_parts)-1];
                    WP_CLI::log('Downloading file '.$file_url.' ...');
                    WP_CLI::launch('curl -L "'.$file_url.'" > '.$tmp_folder.'/'.$file_name);
                    if($key !== 'db'){
                        WP_CLI::launch('unzip '.$tmp_folder.'/'.$file_name.' -d /var/www/html/wp-content');
                    }else{
                        WP_CLI::launch('gzip -d '.$tmp_folder.'/'.$file_name);
                        $sql_dump_path = $tmp_folder.'/'.str_replace('.gz', '', $file_name);
                    }
                }
            }

            WP_CLI::runcommand('config create --dbname=wordpress --dbuser=wordpress --dbpass=wordpress --dbhost=db:3306 --force');
            //Because its a development context, we set debug to true
            WP_CLI::runcommand("config set WP_DEBUG true --raw");
            if(empty($this->site_url)){
                WP_CLI::runcommand("config set WP_HOME \'".$this->server.":\'.getenv\(\'WORDUP_PORT\'\) --raw");
                WP_CLI::runcommand("config set WP_SITEURL \'".$this->server.":\'.getenv\(\'WORDUP_PORT\'\) --raw");
            }else{
                WP_CLI::runcommand("config set WP_HOME ".$this->site_url);
                WP_CLI::runcommand("config set WP_SITEURL ".$this->site_url);
                WP_CLI::runcommand("config set _SERVER[\'HTTP_HOST\']  \'".parse_url($this->site_url, PHP_URL_HOST)."\' --raw --type=variable");
            }
            WP_CLI::runcommand('db import '.$sql_dump_path);
            
            WP_CLI::runcommand('rewrite flush');

            //Delete Tmp Folder
            WP_CLI::launch('rm -r '.$tmp_folder);

            //Delete wordup files on server
            $resp = file_get_contents(Wordup_tools::get_signed_url($url, '/wordup/v1/clean/', $private_key));
            $clean = json_decode($resp, TRUE);
            if($clean && $clean['status'] === 'cleaned'){
                WP_CLI::log('Remote backup files cleaned');
            }
        }

 
        $this->delete_installed_project();
        $this->scaffold_src();
    }

    //Check if current sourc code from plugin/theme is installed, and delete it
    private function delete_installed_project(){

        if($this->wp_package['type'] === 'themes'){
            $is_installed = WP_CLI::runcommand('theme is-installed '.$this->wp_package['slug'], array('return'=>'return_code','exit_error'=>false));
            if(intval($is_installed) === 0){
                WP_CLI::log('Delete duplicate theme from source');
                WP_CLI::runcommand('theme delete '.$this->wp_package['slug']);
            }
        }else if($this->wp_package['type'] === 'plugins'){
            $is_installed = WP_CLI::runcommand('plugin is-installed '.$this->wp_package['slug'], array('return'=>'return_code','exit_error'=>false));

            if(intval($is_installed) === 0){
                WP_CLI::log('Delete duplicate plugin from source');
                WP_CLI::runcommand('plugin delete '.$this->wp_package['slug']);
            }
        }

    }

    private function scaffold_src() {

        if($this->scaffold){
            //Check if .scaffold file exists and delete it 
            if(is_file('/src/.scaffold')){
                WP_CLI::launch('unlink /src/.scaffold');
            }

            if(Wordup_tools::is_dir_empty('/src')){

                $internal_name = Wordup_tools::get_project_dirname($this->wp_package);
                $internal_path = '/var/www/html/wp-content/'.$this->wp_package['type'].'/'.$internal_name;
    
                if($this->wp_package['type'] == 'themes'){
                    WP_CLI::runcommand('scaffold _s '.$internal_name);
                }else if($this->wp_package['type'] == 'plugins'){
                    WP_CLI::runcommand('scaffold plugin '.$internal_name);
                }

                //Move all files to src and delete folder 
                WP_CLI::launch('cp -r '.$internal_path.'/. /src/');
                WP_CLI::launch('rm -r '.$internal_path);
            }else{
                WP_CLI::error( "Could not scaffold data, folder is not empty");
            }
        }
    }


}

WP_CLI::add_command( 'wordup', 'Wordup_Commands' );