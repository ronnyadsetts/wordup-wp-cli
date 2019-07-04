<?php

class Wordup_tools {

    protected $package_types = array('themes', 'plugins','installation');

    public static function get_project_dirname($config){
        if($config['type'] === 'plugins'){
            return dirname($config['slug']);
        }
        return $config['slug'];
    }

    public static function is_dir_empty($dir){
        if(!is_readable($dir)) return NULL;
        return (count(scandir($dir)) == 2);
    }

    public static function wp_package_path_exists($config, $check_slug=FALSE){

        if($config['type'] === 'installation'){
            $project_path = '/var/www/html/wp-content';
        }else{
            if(!$check_slug){
                $project_path = '/var/www/html/wp-content/'.$config['type'].'/'.self::get_project_dirname($config);
            }else{
                $project_path = '/var/www/html/wp-content/'.$config['type'].'/'.$config['slug'];
            }
        }
        return file_exists($project_path);
    }

    public static function connect_src_with_wp($config){

        if($config['type'] === 'installation'){
            WP_CLI::log('Connect WordPress installation');
            if(Wordup_tools::is_dir_empty('/src')){
                WP_CLI::log('Use default wp-content WordPress folder');
                WP_CLI::launch('cp -r /var/www/html/wp-content/. /src/');
            }
            WP_CLI::launch('rm -r /var/www/html/wp-content');
            WP_CLI::launch('ln -s /src /var/www/html/wp-content');

        }else{

            //Connect src with wordpress
            WP_CLI::log('Connect your '.$config['type'].' source code with WordPress');

            $wp_path = '/var/www/html/wp-content/'.$config['type'];
            WP_CLI::launch('ln -s /src '.$wp_path.'/'.self::get_project_dirname($config));
            
            //Activate Plugin
            if( $config['type'] === 'plugins' && self::wp_package_path_exists($config, TRUE)){
                WP_CLI::runcommand('plugin activate '.$config['slug']);
            }

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

    public static function overwrite_style_css($path, $config){
        $css = array(
            "/*",
            "Theme Name: ".$config['projectName'],
            "Theme URI: THEME SITE HERE",
            "Author: YOUR NAME HERE",
            "Author URI: YOUR SITE HERE",
            "Description: THEME DESCRIPTION HERE",
            "Version: 0.1.0",
            "License: GNU General Public License v2 or later",
            "License URI: http://www.gnu.org/licenses/gpl-2.0.html",
            "Tags: YOUR TAGS HERE",
            "Text Domain: ".self::get_project_dirname($config),
            "*/"
        );
        file_put_contents(WP_CLI\Utils\trailingslashit($path).'style.css', implode("\n",$css));
        WP_CLI::log("Overwrite style.css file");
    }
}


class Wordup_Commands {

    public $config;
    public $server = 'http://localhost';
    public $server_port = '8000';
    public $site_url = FALSE;
    public $scaffold = FALSE;
    public $user_data = FALSE;

    public $wordup_folder = '/wordup';
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
     * : A base64 encoded json string, with the wordup config 
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
     * [--scaffold[=<value>]]
     * : Scaffold project src data. Optional the boilerplate project can be set.
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

        Wordup_tools::connect_src_with_wp($this->config);

        WP_CLI::success( "WordPress development stack successfully installed under $this->server:$this->server_port" );
    }

    /**
     * Export /src or whole installation to a zip
     *
     * ## OPTIONS
     * 
     * <config>
     * : A base64 encoded json string, with the wordup config 
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

        $project_folder_name = Wordup_tools::get_project_dirname($this->config);

        //Create tmp folder 
        $export_tmp = '/tmp/wordup-export/';
        if($export_type === 'src' || $export_type === 'installation'){

            if (file_exists('/tmp/wordup-export/')) {
                WP_CLI::launch('rm -r /tmp/wordup-export/');
            }
            mkdir('/tmp/wordup-export');

            //If there is no .distignore create it 
            if(!is_file('/src/.distignore')){
                file_put_contents('/src/.distignore', implode("\n",array('.distignore','.git','.gitignore','node_modules')));
            }
        }

        //Export Theme/plugin src
        if($export_type === 'src'){

            //Only execute if project slug exists
            if(!Wordup_tools::wp_package_path_exists($this->config, TRUE)){
                WP_CLI::error("Your project slug doesn't correspond with your file structure.");
            }

            $export_version = FALSE;
            if($this->config['type'] === 'themes'){
                $export_version = WP_CLI::runcommand('theme get '.$this->config['slug'].' --field=version', array('return' => true));
            }else if($this->config['type'] === 'plugins'){
                $export_version = WP_CLI::runcommand('plugin get '.$this->config['slug'].' --field=version', array('return' => true));
            }else if($this->config['type'] === 'installation'){
                $export_version = 'wp-content';
            }

            if($export_version){
                $final_zip = $project_folder_name.'-'.$export_version.'.zip';

                WP_CLI::runcommand('dist-archive /src '.$export_tmp.'src.tar.gz --format=targz');
                WP_CLI::launch('tar -xvf '.$export_tmp.'src.tar.gz -C '.$export_tmp);
                WP_CLI::launch('mv '.$export_tmp.'src '.$export_tmp.$project_folder_name);
                WP_CLI::launch('cd '.$export_tmp.' && zip -r '.$final_zip.' '.$project_folder_name);
                WP_CLI::launch('mv '.$export_tmp.$final_zip.' /dist/'.$final_zip);
                WP_CLI::log('Transform src.tar.gz -> dist/'.$final_zip);
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

            $project_tmp_path = $export_tmp.'wp-content/'.$this->config['type'].'/'.$project_folder_name;
            if($this->config['type'] === 'installation'){
                $project_tmp_path = $export_tmp.'wp-content';
            }

            WP_CLI::launch('cp -a /var/www/html/. '.$export_tmp);

            //Export Sql
            WP_CLI::log('Creating DB snapshot ...');
            $sql_file = 'db-snapshot-'.time().'.sql';
            WP_CLI::runcommand('db export '.$export_tmp.$sql_file, array('return'=>true));

            //Create wordup-archive 
            $wp_version = WP_CLI::runcommand('core version', array('return'=>true));
            $wordup_archive_params= Wordup_tools::create_wordup_archive($wp_version, array('db'=>$sql_file));
            file_put_contents($export_tmp.'wordup-archive.json', json_encode($wordup_archive_params, JSON_PRETTY_PRINT));
            WP_CLI::log('Writing wordup-archive.json for WP-Version: '.$wp_version);

            // ---- Export src with dist-archive and add to installation ----
            unlink($project_tmp_path);  //Unlink plugin/theme/wp-content symlink

            WP_CLI::runcommand('dist-archive /src '.$export_tmp.'src.tar.gz --format=targz', array('return'=>true));
            WP_CLI::launch('tar -xvf '.$export_tmp.'src.tar.gz -C '.$export_tmp);
            WP_CLI::launch('mv '.$export_tmp.'src '.$project_tmp_path);
            unlink($export_tmp.'src.tar.gz');

            $filename = !empty($assoc_args['filename']) ? $assoc_args['filename'] : 'installation-'.date('Y-m-d');

            //Create archive
            WP_CLI::log('Creating installation archive ...');
            WP_CLI::launch('cd '.$export_tmp.' && tar czf /dist/'. $filename.'.tar.gz .');
            WP_CLI::launch('rm -r '.$export_tmp);
        }

        WP_CLI::success( "Successfully exported $export_type" );
    }

    private function parse_config($config){
        $config_json = json_decode(base64_decode($config), true); 
        if(!is_array($config_json)){
            WP_CLI::error( "Could not parse wordup config.yml" );
        }else if(empty($config_json['slug']) || empty($config_json['type'])){
            WP_CLI::error( "Could not find settings in config.yml" );
        }else{
            WP_CLI::log( "Parsed wordup config.yml with slug: ".$config_json['slug'] );
        }
        $this->config  = $config_json;
    }

    private function install_from_scratch() {
        $installation_config = $this->config['wpInstall'];

        $all_users = $installation_config['users'];
        $admin = $all_users[0]; // The first user is always the admin

        //Install basic stuff
        WP_CLI::runcommand('core install  \
                    --path="/var/www/html" \
                    --url="'.(!empty($this->site_url) ? $this->site_url : $this->server.':'.$this->server_port).'" \
                    --title="'.$installation_config['title'].'" \
                    --admin_user="'.$admin['name'].'" \
                    --admin_password="'.$admin['password'].'" \
                    --admin_email="'.$admin['email'].'" \
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

        // ------ Install custom language ------
        if(!empty($installation_config['language']) && $installation_config['language'] !== 'en_US'){
            WP_CLI::runcommand("language core install ".$installation_config['language']." --activate");
        }

        // ------ Check which version ------
        if(!empty($installation_config['version'])){
            WP_CLI::runcommand("core update --force ".($installation_config['version'] !== 'latest' ? "version=".$installation_config['version'] : ''));
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

        // ------- Scaffold source code ------
        $this->scaffold_src();

        // ------- Import data like media, posts, pages -----
        $this->import_data();

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
        $htaccess = array("# BEGIN WordPress",
                "<IfModule mod_rewrite.c>",
                "RewriteEngine On",
                "RewriteBase /",
                "RewriteRule ^index\.php$ - [L]",
                "RewriteCond %{REQUEST_FILENAME} !-f",
                "RewriteCond %{REQUEST_FILENAME} !-d",
                "RewriteRule . /index.php [L]",
                "</IfModule>",
                "# END WordPress"
        );
        file_put_contents('/var/www/html/.htaccess', implode("\n",$htaccess));

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

        if($this->config['type'] === 'themes'){
            $is_installed = WP_CLI::runcommand('theme is-installed '.$this->config['slug'], array('return'=>'return_code','exit_error'=>false));
            if(intval($is_installed) === 0){
                WP_CLI::log('Delete duplicate theme from source');
                WP_CLI::runcommand('theme delete '.$this->config['slug']);
            }
        }else if($this->config['type'] === 'plugins'){
            $is_installed = WP_CLI::runcommand('plugin is-installed '.$this->config['slug'], array('return'=>'return_code','exit_error'=>false));

            if(intval($is_installed) === 0){
                WP_CLI::log('Delete duplicate plugin from source');
                WP_CLI::runcommand('plugin delete '.$this->config['slug']);
            }
        }

    }

    private function scaffold_src() {

        //Scaffold data only in plugin or theme projects
        if($this->scaffold && $this->config['type'] !== 'installation'){
            //Check if .scaffold file exists and delete it 
            if(is_file('/src/.scaffold')){
                WP_CLI::launch('unlink /src/.scaffold');
            }

            if(Wordup_tools::is_dir_empty('/src')){

                $internal_name = Wordup_tools::get_project_dirname($this->config);
                $internal_path = '/var/www/html/wp-content/'.$this->config['type'].'/'.$internal_name;
                
                if($this->config['type'] == 'themes'){

                    if($this->scaffold === 'understrap'){
                        Wordup_tools::extract_remote_zip_to_wp_content('https://github.com/understrap/understrap/archive/master.zip', $internal_name, 'themes');
                        Wordup_tools::overwrite_style_css($internal_path, $this->config);
                    }else{
                        WP_CLI::runcommand('scaffold _s '.$internal_name);
                    }

                }else if($this->config['type'] == 'plugins'){
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

    private function import_data($reset=FALSE) {
        $parser = new Mni\FrontYAML\Parser();

        $added_users = array();
        $added_media = array();
        $added_posts = array();
        $added_menus = array();
        $added_categories = array();

        $wpInstall = $this->config['wpInstall'];

        // Import User Roles & users
        if(!empty($wpInstall['roles']) && is_array($wpInstall['roles'])){
            foreach($wpInstall['roles'] as $key => $role){

                WP_CLI::runcommand('role create '.$role['key'].' '.escapeshellarg($role['name']).(!empty($role['clone_from']) ? ' --clone='.$role['clone_from'] : ''), array('exit_error'=>false));   
                
                if(!empty($role['capabilities']) && is_array($role['capabilities'])){
                    foreach($role['capabilities'] as $cap){
                        WP_CLI::runcommand('cap add '.$role['key'].' '.$cap, array('exit_error'=>false));   
                    }
                }
            }
        }

        // Import users
        $users = $wpInstall['users'];
        foreach($users as $key => $user){
            //The first user is always the admin, and was created with the install
            if($key !== 0){                
                $id = WP_CLI::runcommand('user create '.escapeshellarg($user['name']).' '.$user['email'].' --role='.$user['role'].' --user_pass='.escapeshellarg($user['password']).' --porcelain', array('return' => true, 'exit_error'=>false));   
                if(!empty($id)){
                    $added_users[$id] = $user['name']; 
                }
            }
        }
        

        // Import media files
        $media_folder = WP_CLI\Utils\trailingslashit($this->wordup_folder).'media';
        $added_media = array();
        if(is_readable($media_folder)){
            $media_files = array_diff(scandir($media_folder), array('..', '.'));
            foreach($media_files as $file){
                $id = WP_CLI::runcommand('media import '.WP_CLI\Utils\trailingslashit($media_folder).$file.' --porcelain --user=1', array('return' => true));
                $added_media[$id] = $file;
            }
            if(count($added_media) > 0){
                WP_CLI::log('Successfully added media '.count($added_media).' file(s)');
            }
        }
        
        // Import pages and posts
        $post_types = array('post','page');
        foreach($post_types as $post_type){
            $post_folder = WP_CLI\Utils\trailingslashit($this->wordup_folder).$post_type;
            if(is_readable($post_folder)){
                $post_type_ids = array(); //Just the added post type ids for each post_type

                $post_files = array_diff(scandir($post_folder), array('..', '.'));
                rsort($post_files);
                
                //if there are custom posts, delete the default ones first
                if(count($post_files) > 0){
                    $old_post_ids = WP_CLI::runcommand("post list --post_type=".$post_type." --format=ids", array('return' => true));
                    WP_CLI::runcommand("post delete ".$old_post_ids.(($reset === TRUE) ? " --force" : ""));
                }

                //Extract all data
                foreach($post_files as $key => $file){

                    $content_tmp = $this->tmp_path.uniqid();
                    $document = $parser->parse(file_get_contents(WP_CLI\Utils\trailingslashit($post_folder).$file));
                    
                    $options = $document->getYAML();
                    $post_content = $document->getContent();

                    if(!$options || !$post_content || !array_key_exists('title', $options)){
                        continue;
                    }

                    //Set post status
                    $post_status = !empty($options['status']) ? $options['status'] : 'publish';

                    //Check if post has parent item
                    $prev_id = $key-1;
                    $post_parent = 0;
                    $prev_filename = ($prev_id >= 0) ? pathinfo($post_files[$prev_id], PATHINFO_FILENAME) : '';
                    if($post_type !== 'post' && !empty($prev_filename) && strpos($file, $prev_filename.'--') !== FALSE){
                        if(!empty($post_type_ids)){
                            $post_parent = $post_type_ids[$prev_id];
                        }
                    }

                    //Check if a featured image should be set for this post
                    $add_to_cmd = '';
                    if(!empty($options['featured_image'])){
                        $attachment_id = array_search(trim($options['featured_image']), $added_media);
                        if($attachment_id){
                            $add_to_cmd .= '--_thumbnail_id='.$attachment_id;
                        }
                    }

                    //Check if tags should be added
                    if($post_type === 'post'){
                        if(!empty($options['tags'])){
                            $items = explode(";", $options['tags']);
                            if(count($items) > 0){
                                $items = array_map('trim',$items);
                                $add_to_cmd .= ' --tags_input='.escapeshellarg(implode(",", $items));
                            }
                        }
                    }

                    //Extract categories
                    if($post_type === 'post' && !empty($options['category'])){
                        $categories = explode(";", $options['category']);

                        if(!empty($categories)){
                            $post_categories = array_map('trim', $categories);
                            $add_cat_ids = array();
                            foreach($post_categories as $cat){
                                //Check if new category has to be created
                                if(!in_array($cat, $added_categories)){
                                    $cat_id = WP_CLI::runcommand("term create category ".escapeshellarg($cat)." --porcelain", array('return' => true));
                                    $added_categories[$cat_id] = $cat;
                                }else{
                                    $cat_id = array_search($cat, $added_categories);
                                }
                                //add category to post
                                $add_cat_ids[] = $cat_id;
                            }
                            $add_to_cmd .= ' --post_category='.implode(",", $add_cat_ids);
                        }
                    }


                    //Set auther according to settings, default is first user
                    $author = 1;
                    if(!empty($options['author'])){
                        $author_id = array_search($options['author'], $added_users);
                        if($author_id){
                            $author = $author_id;
                        }
                    }


                    //Save content in tmp file
                    file_put_contents($content_tmp, $post_content);
                    $id = WP_CLI::runcommand("post create  \
                            --post_type=".$post_type."  \
                            --post_title=".escapeshellarg($options['title'])." \
                            --post_author=".$author."  \
                            --post_status=".$post_status." \
                            --post_parent=".$post_parent." \
                            --porcelain ".$add_to_cmd." ".$content_tmp, array('return' => true));
                    $added_posts[$id] = array('title'=>$options['title']);
                    $post_type_ids[] = $id;

                    unlink($content_tmp);

                    //Finally check if menus have to be added
                    if(!empty($options['menu'])){
                        $menus = explode(";", $options['menu']);
                        if(!empty($menus)){
                            $post_menus = array_map('trim', $menus);
                            foreach($post_menus as $menu){
                                //Check if new menu has to be created
                                if(!in_array($menu, $added_menus)){
                                    $menu_id = WP_CLI::runcommand("menu create ".escapeshellarg($menu)." --porcelain", array('return' => true));
                                    $added_menus[$menu_id] = $menu;
                                }else{
                                    $menu_id = array_search($menu, $added_menus);
                                }
                                //add post to menu
                                WP_CLI::runcommand("menu item add-post ".$menu_id." ".$id." --porcelain", array('return' => true));
                            }
                        }
                    }
                }
            }
        }
        
    }


}

WP_CLI::add_command( 'wordup', 'Wordup_Commands' );