<?php

class StaticHtmlOutput_Controller {
    const VERSION = '5.9';
    const OPTIONS_KEY = 'wp2static-options';
    const HOOK = 'wp2static';

    protected static $_instance = null;

    protected function __construct() {}

    public static function getInstance() {
        if ( null === self::$_instance ) {
            self::$_instance = new self();
            self::$_instance->options = new StaticHtmlOutput_Options(
                self::OPTIONS_KEY
            );
            self::$_instance->view = new StaticHtmlOutput_View();
        }

        return self::$_instance;
    }


    public static function init( $bootstrapFile ) {
        $instance = self::getInstance();

        register_activation_hook(
            $bootstrapFile,
            array( $instance, 'activate' )
        );

        if ( is_admin() ) {
            add_action(
                'admin_menu',
                array(
                    $instance,
                    'registerOptionsPage',
                )
            );
            add_filter( 'custom_menu_order', '__return_true' );
            add_filter( 'menu_order', array( $instance, 'set_menu_order' ) );
        }
        return $instance;
    }


    public function set_menu_order( $menu_order ) {
        $order = array();
        $file  = plugin_basename( __FILE__ );
        foreach ( $menu_order as $index => $item ) {
            if ( $item === 'index.php' ) {
                $order[] = $item;
            }
        }

        $order = array(
            'index.php',
            'wp2static',
        );

        return $order;
    }


    public function setDefaultOptions() {
        if ( null === $this->options->getOption( 'version' ) ) {
            $this->options
            ->setOption( 'version', self::VERSION )
            ->setOption( 'static_export_settings', self::VERSION )
            // set default options
            ->setOption( 'rewriteWPPaths', '1' )
            ->setOption( 'removeConditionalHeadComments', '1' )
            ->setOption( 'removeWPMeta', '1' )
            ->setOption( 'discoverNewURLs', '1' )
            ->setOption( 'removeWPLinks', '1' )
            ->setOption( 'removeHTMLComments', '1' )
            ->save();
        }
    }

    public function activate_for_single_site() {
        $this->setDefaultOptions();
    }

    public function activate( $network_wide ) {
        if ( $network_wide ) {
            global $wpdb;

            $query = 'SELECT blog_id FROM %s WHERE site_id = %d;';

            $site_ids = $wpdb->get_col(
                sprintf(
                    $query,
                    $wpdb->blogs,
                    $wpdb->siteid
                )
            );

            foreach ( $site_ids as $site_id ) {
                switch_to_blog( $site_id );
                $this->activate_for_single_site();
            }

            restore_current_blog();
        } else {
            $this->activate_for_single_site();
        }
    }

    public function registerOptionsPage() {
        $pluginDirUrl = plugin_dir_url( dirname( __FILE__ ) );
        $page = add_menu_page(
            __( 'WP2Static', 'static-html-output-plugin' ),
            __( 'WP2Static', 'static-html-output-plugin' ),
            'manage_options',
            self::HOOK,
            array( self::$_instance, 'renderOptionsPage' ),
            'dashicons-shield-alt'
        );

        add_action(
            'admin_print_styles-' . $page,
            array(
                $this,
                'enqueueAdminStyles',
            )
        );
    }

    public function enqueueAdminStyles() {
        $pluginDirUrl = plugin_dir_url( dirname( __FILE__ ) );

        wp_enqueue_style(
            self::HOOK . '-admin',
            $pluginDirUrl . '/views/wp2static.css',
            null,
            $this::VERSION
        );
    }

    public function generate_filelist_preview() {
        // TODO: DRY up WPSite calls
        require_once dirname( __FILE__ ) . '/StaticHtmlOutput/WPSite.php';
        $this->wp_site = new WPSite();

        // TODO: move to WPSite
        $plugin_hook = 'wp2static';

        $initial_file_list_count =
            StaticHtmlOutput_FilesHelper::buildInitialFileList(
                true,
                $this->wp_site->wp_uploads_path,
                $this->wp_site->uploads_url,
                $this->wp_site->wp_uploads_path,
                $this->wp_site->site_url
            );

        if ( ! defined( 'WP_CLI' ) ) {
            echo $initial_file_list_count;
        }
    }

    // TODO: send these to initial page load and pass as new settings set
    public function setOldNewPaths() {

    }

    public function renderOptionsPage() {
        require_once dirname( __FILE__ ) . '/StaticHtmlOutput/WPSite.php';

        $this->wp_site = new WPSite();
        $this->current_archive = '';

        $this->view
            ->setTemplate( 'options-page-js' )
            ->assign( 'working_directory', $this->getWorkingDirectory() )
            ->assign( 'options', $this->options )
            ->assign( 'wp_site', $this->wp_site )
            ->assign( 'onceAction', self::HOOK . '-options' )
            ->render();

        $this->view
            ->setTemplate( 'options-page' )
            ->assign( 'wp_site', $this->wp_site )
            ->assign( 'options', $this->options )
            ->assign( 'onceAction', self::HOOK . '-options' )
            ->render();
    }

    public function userIsAllowed() {
        $referred_by_admin = check_admin_referer( self::HOOK . '-options' );
        $user_can_manage_options = current_user_can( 'manage_options' );

        return $referred_by_admin && $user_can_manage_options;
    }

    public function save_options() {
        if ( ! $this->userIsAllowed() ) {
            exit( 'Not allowed to change plugin options.' );
        }

        $this->options->saveAllPostData();
    }


    public function getWorkingDirectory() {
        $outputDir = '';

        // priorities: from UI; from settings; fallback to WP uploads path
        if ( isset( $this->workingDirectory ) ) {
            $outputDir = $this->workingDirectory;
            // TODO: fixed this typo, implications?
            // } elseif ( $this->options->oworkingDirectory ) {
        } elseif ( $this->options->workingDirectory ) {
            $outputDir = $this->options->workingDirectory;
        } else {
            $outputDir = $this->wp_site->wp_uploads_path;
        }

        if ( ! is_dir( $outputDir ) && ! wp_mkdir_p( $outputDir ) ) {
            $outputDir = $this->wp_site->wp_uploads_path;
            WsLog::l( 'USER WORKING DIRECTORY UNABLE TO BE SET' );
            error_log( 'USER WORKING DIRECTORY UNABLE TO BE SET' );
        }

        if ( empty( $outputDir ) || ! is_writable( $outputDir ) ) {
            $outputDir = $this->wp_site->wp_uploads_path;
            WsLog::l( 'USER WORKING DIRECTORY NOT WRITABLE' );
            error_log( 'USER WORKING DIRECTORY NOT WRITABLE' );
        }

        // convert to Windows-safe filepath
        // $outputDir = realpath( $outputDir );
        // escape Win URLs for JS
        // $outputDir = json_encode( $outputDir );
        return $outputDir;
    }

    public function prepare_for_export() {
        require_once dirname( __FILE__ ) . '/StaticHtmlOutput/Exporter.php';

        $exporter = new Exporter();

        $exporter->capture_last_deployment();
        $exporter->pre_export_cleanup();
        $exporter->cleanup_leftover_archives();
        $exporter->initialize_cache_files();

        require_once dirname( __FILE__ ) . '/StaticHtmlOutput/Archive.php';

        $archive = new Archive();
        $archive->create();

        $viaCLI = defined( 'WP_CLI' );

        // TODO: move to exporter; wp env vars to views
        $exec_time = ini_get( 'max_execution_time' );
        WsLog::l( 'STARTING EXPORT: ' . date( 'Y-m-d h:i:s' ) );
        WsLog::l( 'STARTING EXPORT: PHP VERSION ' . phpversion() );
        WsLog::l( 'STARTING EXPORT: PHP MAX EXECUTION TIME ' . $exec_time );
        WsLog::l( 'STARTING EXPORT: OS VERSION ' . php_uname() );
        WsLog::l( 'STARTING EXPORT: WP VERSION ' . get_bloginfo( 'version' ) );
        WsLog::l( 'STARTING EXPORT: WP URL ' . get_bloginfo( 'url' ) );
        WsLog::l( 'STARTING EXPORT: WP SITEURL ' . get_option( 'siteurl' ) );
        WsLog::l( 'STARTING EXPORT: WP HOME ' . get_option( 'home' ) );
        WsLog::l( 'STARTING EXPORT: WP ADDRESS ' . get_bloginfo( 'wpurl' ) );
        WsLog::l( 'STARTING EXPORT: PLUGIN VERSION ' . $this::VERSION );
        WsLog::l( 'STARTING EXPORT: VIA WP-CLI? ' . $viaCLI );
        WsLog::l(
            'STARTING EXPORT: STATIC EXPORT URL ' .
            $exporter->settings['baseUrl']
        );

        $exporter->generateModifiedFileList();

        if ( ! defined( 'WP_CLI' ) ) {
            echo 'SUCCESS';
        }
    }

    public function reset_default_settings() {
        if ( ! delete_option( 'wp2static-options' ) ) {
            error_log( "Couldn't reset plugin to default settings" );
        }

        $this->options = new StaticHtmlOutput_Options( self::OPTIONS_KEY );
        $this->setDefaultOptions();

        echo 'SUCCESS';
    }

    public function post_process_archive_dir() {
        require_once dirname( __FILE__ ) .
            '/StaticHtmlOutput/ArchiveProcessor.php';
        $processor = new ArchiveProcessor();

        $processor->create_symlink_to_latest_archive();
        $processor->createNetlifySpecialFiles();
        // NOTE: renameWP Directories also doing same server publish
        $processor->renameWPDirectories();
        $processor->create_zip();
    }
}
