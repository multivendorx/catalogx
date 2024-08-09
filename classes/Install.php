<?php
namespace CatalogEnquiry;

class Install {
    const VERSION_KEY = 'catalog_enquiry_plugin_version';

    const FREE_FORM_MAP = [
        'name'           => 'name-label',
        'email'          => 'email-label',
        'phone'          => 'is-phone',
        'address'        => 'is-address',
        'subject'        => 'is-subject',
        'comment'        => 'is-comment',
        'fileupload'     => 'is-fileupload',
        'filesize-limit' => 'filesize-limit',
        'captcha'        => 'is-captcha',
    ];

    const PRO_FORM_TYPE_MAP = [
        'p_title' => 'title',
        'textbox' => 'text',
    ];

    public static $previous_version = '';

    public static $current_version  = '';

    /**
     * Install class constructor functions
     */
    public function __construct() {

        // Get the previous version and current version
        self::$previous_version = get_option( self::VERSION_KEY, '' );
        self::$current_version  = Catalog()->version;

        $this->create_database_table();

        $this->set_default_modules();

        $this->migrate_database_table();
        
        $this->set_default_settings();
        
        $this->create_page_for_quote();
        $this->create_page_for_quote_thank_you();

        // Update the version in database
        update_option( self::VERSION_KEY, self::$current_version );
    }

    /**
     * Create database table for plugin
     * @return void
     */
    private function create_database_table() {
        global $wpdb;

        $collate = '';

        if ($wpdb->has_cap('collation')) {
            $collate = $wpdb->get_charset_collate();
        }

        // Create message table
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `" . $wpdb->prefix . "catelog_cust_vendor_answers` (
                `chat_message_id` bigint(20) NOT NULL AUTO_INCREMENT,
                `to_user_id` bigint(20) NOT NULL,
                `from_user_id` bigint(20) NOT NULL,
                `chat_message` text NOT NULL,
                `product_id` text NOT NULL,
                `enquiry_id` bigint(20) NOT NULL,
                `status` varchar(20) NOT NULL,
                `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`chat_message_id`)
            ) $collate;"
        );

        // Create enquiry table
        $wpdb->query(
           "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}" . Utill::TABLES[ 'enquiry' ] . "` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `product_info` text NOT NULL,
                `user_id` bigint(20) NOT NULL DEFAULT 0,
                `user_name` varchar(50) NOT NULL,
                `user_email` varchar(50) NOT NULL,
                `user_additional_fields` text NOT NULL,
                `pin_msg_id` bigint(20) DEFAULT NULL,
                `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) $collate;"
        );

        // Create rules table
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}" . Utill::TABLES[ 'rule' ] . "` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `name` text,
                `user_id` bigint(20),
                `role_id` varchar(100),
                `product_id` bigint(20),
                `category_id` bigint(20),
                `quentity` bigint(20) NOT NULL,
                `type` varchar(20) NOT NULL,
                `amount` bigint(20) NOT NULL,
                `priority` bigint(20) NOT NULL,
                `active` boolean NOT NULL DEFAULT true,
                PRIMARY KEY ( `id` )
            ) $collate;"
        );

        if ( version_compare( self::$previous_version, '5.0.8', '<=' ) ) {
            // Rebame the table
            $wpdb->query(
                "ALTER TABLE `{$wpdb->prefix}catelog_cust_vendor_answers` RENAME TO `{$wpdb->prefix}" . Utill::TABLES[ 'message' ] . "`"
            );

            // Add column to table
            $wpdb->query(
                "ALTER TABLE `{$wpdb->prefix}" . Utill::TABLES[ 'message' ] . "`
                ADD COLUMN attachment bigint(20);"
            );
            $wpdb->query(
                "ALTER TABLE `{$wpdb->prefix}" . Utill::TABLES[ 'message' ] . "`
                ADD COLUMN reaction varchar(20);"
            );
            $wpdb->query(
                "ALTER TABLE `{$wpdb->prefix}" . Utill::TABLES[ 'message' ] . "`
                ADD COLUMN star boolean;"
            );
        }
    }

    /**
     * Set default modules
     * @return void
     */
    public static function set_default_modules() {
        if ( version_compare( self::$previous_version, '5.0.8', '<=' ) ) {
            
            // Enable enquiry module by default
            $active_module_list = [ 'enquiry' ];
            
            $previous_settings = get_option( 'woocommerce_catalog_enquiry_general_settings', [] );

            // Enable catalog setting based on previous setting
            if ( isset( $previous_settings[ 'is_enable' ] ) && $previous_settings[ 'is_enable' ] === 'Enable' ) {
                $active_module_list[] = [ 'catalog' ];
            }

            update_option( Modules::ACTIVE_MODULES_DB_KEY, $active_module_list );
        }
    }

    /**
     * Migrate database 
     * @return void
     */
    public function migrate_database_table() {
        global $wpdb;

        if ( version_compare( self::$previous_version, '5.0.8', '<=' ) ) {
            try {
                // Get enquiry post and post meta
                $enquirys_datas = $wpdb->get_results(
                    "SELECT p.ID as id,
                        p.post_author as user_id,
                        p.post_date as date,
                        MAX(CASE WHEN pm.meta_key = '_enquiry_username' THEN pm.meta_value END) as username,
                        MAX(CASE WHEN pm.meta_key = '_enquiry_useremail' THEN pm.meta_value END) as useremail,
                        MAX(CASE WHEN pm.meta_key = '_user_enquiry_fields' THEN pm.meta_value END) as additional_fields,
                        MAX(CASE WHEN pm.meta_key = '_enquiry_product' THEN pm.meta_value END) as product_ids,
                        MAX(CASE WHEN pm.meta_key = '_enquiry_product_quantity' THEN pm.meta_value END) as product_quantitys,
                    FROM {$wpdb->prefix}posts as p
                    JOIN {$wpdb->prefix}postmeta as pm ON p.ID = pm.post_id
                    WHERE p.post_type = 'wcce_enquiry'
                    GROUP BY p.ID",
                    ARRAY_A
                );

                foreach ( $enquirys_datas as $id => $enquiry ) {
                    $product_ids        = $enquiry[ 'product_ids' ];
                    $product_quantitys  = $enquiry[ 'product_quantitys' ];

                    $wpdb->insert(
                        "{$wpdb->prefix}" . Utill::TABLES['rule'],
                        [
                            'id'                     => $enquiry[ 'id' ],
                            'user_id'                => $enquiry[ 'user_id' ],
                            'user_name'              => $enquiry[ 'user_name' ],
                            'user_email'             => $enquiry[ 'useremail' ],
                            'user_additional_fields' => $enquiry[ 'additional_fields' ],
                            'date'                   => $enquiry[ 'date' ],
                            'product_info'           => [ $product_ids => $product_quantitys ],
                        ]
                    );

                    // Delete the posts
                    wp_delete_post( $enquiry[ 'id' ], false );
                }
            } catch ( \Exception $e ) {
                Utill::log( $e->getMessage() );
            }
        }
    }

    /**
     * Set default settings
     * @return void
     */
    public function set_default_settings() {
        // Migration by version controll
        if ( version_compare( self::$previous_version, '5.0.8', '<=' ) ) {
            
            $previous_general_settings = get_option( 'mvx_catalog_general_tab_settings', [] );
            // $previous_general_settings = $previous_general_settings[ 'woocommerce_catalog_enquiry_general_settings' ] ?? [];

            // Update product page builder
            $page_builder_setting = [
                'hide_product_price' => $previous_general_settings[ 'is_remove_price_free' ] == "is_remove_price_free",
                'additional_input'   => $previous_general_settings[ 'replace_text_in_price' ] ?? '',
            ];

            update_option( 'catalog_enquiry_catalog_customization_settings', $page_builder_setting );

            
            // Update shopping gurnal
            $all_settings = [
                'is_enable_out_of_stock'  => $previous_general_settings[ 'is_enable_out_of_stock' ] == "Enable",
                'enquiry_user_permission' => $previous_general_settings[ 'for_user_type' ] == 3,
                'is_page_redirect'        => $previous_general_settings[ 'is_enable_enquiry' ] == 'is_enable_enquiry',
                'is_disable_popup'        => $previous_general_settings[ 'is_disable_popup' ] == 'is_disable_popup' ? 'inline' : 'popup',
            ];

            update_option( 'catalog_all_settings_settings', $all_settings );


            // Update tools settings
            $tool_settings = [
                'set_enquiry_cart_page'  => intval( get_option( 'catalog_enquiry_cart_page' ) ),
                'set_request_quote_page' => intval( get_option( 'request_quote_page' ) )
            ];

            update_option( 'catalog_tools_settings', $tool_settings );
            
            //// Update form settings
            
            // Free form migration
            $previous_free_from_setting = get_option( 'mvx_catalog_enquiry_form_tab_settings', [] );

            $free_form = [
                [
                    'key'       => 'name',
                    'label'     => 'Enter your name',
                    'active'    => true,
                ],
                [
                    'key'       => 'email',
                    'label'     => 'Enter your email',
                    'active'    => true,
                ],
                [ "key" => "phone" ],
                [ "key" => "address" ],
                [ "key" => "subject" ],
                [ "key" => "comment" ],
                [ "key" => "fileupload" ],
                [ "key" => "filesize-limit" ],
                [ "key" => "captcha" ],
            ];
            

            $previous_free_from = $previous_free_from_setting[ 'enquiry_form_fileds' ];

            if ( is_array( $previous_free_from ) ) {
                
                $previous_free_from_keys = array_column( $previous_free_from, 0 );

                $free_form = array_map( function ( $form ) use ( $previous_free_from, $previous_free_from_keys ) {
                    
                    // Get label key and active status key
                    $label_key  = self::FREE_FORM_MAP[ $form[ 'key' ] ];
                    $active_key = "{$label_key}_checkbox";

                    $label_index  = array_search( $label_key, $previous_free_from_keys );
                    $active_index = array_search( $active_key, $previous_free_from_keys );

                    $label  = $form[ 'label' ] ?? '';
                    $active = $form[ 'active' ] ?? false;

                    $label  = $label_index ? $previous_free_from[ $label_index ][ 1 ] : $label;
                    $active = $active_index ? $previous_free_from[ $active_index ][ 1 ] : $active;

                    return [
                        'key'       => $form[ 'key' ],
                        'label'     => $label,
                        'active'    => $active,
                    ];

                }, $free_form );
            }

            // Pro form migration
            $previous_pro_from_setting = get_option( 'mvx_catalog_pro_enquiry_form_data', [] );

            if ( is_array( $previous_pro_from_setting ) ) {
                $pro_form = array_map( function ( $form ) {
                    return [
                        ...$form,
                        'name' => $form[ 'name' ],
                        'type' => self::PRO_FORM_TYPE_MAP[ $form[ 'type' ] ] ?? $form[ 'type' ],
                    ];
                }, $previous_pro_from_setting );
            } else {
                $pro_form = [ 
                    [
                        'id'      => 1,
                        'type'    => 'title',
                        'label'   => 'Enquiry Form',
                    ],
                    [
                        'id'           => 2,
                        'type'         => 'text',
                        'label'        => 'Enter your name',
                        'required'     => true,
                        'placeholder'  => 'I am default place holder',
                        'name'         => 'name',
                        'not_editable' => true
                    ],
                    [
                        'id'           => 3,
                        'type'         => 'email',
                        'label'        => 'Enter your email',
                        'required'     => true,
                        'placeholder'  => 'I am default place holder',
                        'name'         => 'email',
                        'not_editable' => true
                    ],
                ];
            }

            $form_settings = [
                'formsettings'    => [
                    'formfieldlist'  => $pro_form,
                    'butttonsetting' => [],
                ],
                'freefromsetting' => $free_form,          
            ];
    
            update_option( 'catalog_enquiry_form_customization_settings', $form_settings );

            //// Update exclusion settings
            $previous_exclusion_settings = get_option( 'mvx_catalog_exclusion_tab_settings', [] );

            // Prepare exclusion user list
            $exclusion_user_list = $previous_exclusion_settings[ 'woocommerce_user_list' ];
            $exclusion_user_list = is_array( $exclusion_user_list ) ? $exclusion_user_list : [];

            $exclusion_user_list = array_map(function ($user_list) {
                return [
                    'key'   => $user_list[ 'value' ],
                    'label' => $user_list[ 'label' ],
                    'value'	=> $user_list[ 'value' ],
                ];
            }, $exclusion_user_list );

            // Prepare user role list
            $exclusion_userroles_list = $previous_exclusion_settings[ 'woocommerce_userroles_list' ];
            $exclusion_userroles_list = is_array( $exclusion_userroles_list ) ? $exclusion_userroles_list : [];

            $exclusion_userroles_list = array_map(function ($user_list) {
                return [
                    'key'   => $user_list[ 'value' ],
                    'label' => $user_list[ 'label' ],
                    'value'	=> $user_list[ 'value' ],
                ];
            }, $exclusion_userroles_list );

            // Prepare product list
            $exclusion_product_list = $previous_exclusion_settings[ 'woocommerce_product_list' ];
            $exclusion_product_list = is_array( $exclusion_product_list ) ? $exclusion_product_list : [];

            $exclusion_product_list = array_map(function ($user_list) {
                return [
                    'key'   => $user_list[ 'value' ],
                    'label' => $user_list[ 'label' ],
                    'value'	=> $user_list[ 'value' ],
                ];
            }, $exclusion_product_list );

            // Prepare category list
            $exclusion_category_list = $previous_exclusion_settings[ 'woocommerce_category_list' ];
            $exclusion_category_list = is_array( $exclusion_category_list ) ? $exclusion_category_list : [];

            $exclusion_category_list = array_map( function ( $user_list ) {
                return [
                    'key'   => $user_list[ 'value' ],
                    'label' => $user_list[ 'label' ],
                    'value'	=> $user_list[ 'value' ],
                ];
            }, $exclusion_category_list );

            $exclusion_settings = [
                'catalog_exclusion_user_list'       => $exclusion_user_list,
                'enquiry_exclusion_user_list'       => $exclusion_user_list,
                'quote_exclusion_user_list'         => $exclusion_user_list,

                'quote_exclusion_userroles_list'    => $exclusion_userroles_list,
                'catalog_exclusion_userroles_list'  => $exclusion_userroles_list,
                'enquiry_exclusion_userroles_list'  => $exclusion_userroles_list,

                'enquiry_exclusion_product_list'    => $exclusion_product_list,
                'catalog_exclusion_product_list'    => $exclusion_product_list,
                'quote_exclusion_product_list'      => $exclusion_product_list,

                'catalog_exclusion_category_list'   => $exclusion_category_list,
                'enquiry_exclusion_category_list'   => $exclusion_category_list,
                'quote_exclusion_category_list'     => $exclusion_category_list,
            ];

            update_option( 'catalog_enquiry_quote_exclusion_settings', $exclusion_settings );
        }
    }

    /**
     * Create page for quote
     * @return void
     */
    public function create_page_for_quote() {
        // quote page
        $option_value = get_option('request_quote_page');
        if ($option_value > 0 && get_post($option_value)) {
            return;
        }

        $page_found = get_posts([
            'name' => 'request-quote',
            'post_status' => 'publish',
            'post_type' => 'page',
            'fields' => 'ids',
            'numberposts' => 1
        ]);
        if ($page_found) {
            if (!$option_value) {
                update_option('request_quote_page', $page_found[0]);
            }
            return;
        }
        $page_data = [
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_author' => 1,
            'post_name' => 'request-quote',
            'post_title' => __('Request Quote', 'woocommerce-catalog-enquiry-pro'),
            'post_content' => '[request_quote]',
            'comment_status' => 'closed'
        ];
        $page_id = wp_insert_post($page_data);
        update_option('request_quote_page', $page_id);
    }

    /**
     * Create page for quote thakyou
     * @return void
     */
    function create_page_for_quote_thank_you() {
        // quote thank you page
        $option_value = get_option('request_quote_thank_you_page');
        if ($option_value > 0 && get_post($option_value)) {
            return;
        }

        $page_found = get_posts([
            'name' => 'request-quote-thank-you',
            'post_status' => 'publish',
            'post_type' => 'page',
            'fields' => 'ids',
            'numberposts' => 1
        ]);
        if ($page_found) {
            if (!$option_value) {
                update_option('request_quote_thank_you_page', $page_found[0]);
            }
            return;
        }
        $page_data = [
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_author' => 1,
            'post_name' => 'request-quote-thank-you',
            'post_title' => __('Quotation Confirmation', 'woocommerce-catalog-enquiry-pro'),
            'post_content' => '[request_quote_thank_you]',
            'comment_status' => 'closed'
        ];
        $page_id = wp_insert_post($page_data);
        update_option('request_quote_thank_you_page', $page_id);
    }

}