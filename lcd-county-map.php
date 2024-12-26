<?php
/**
 * Plugin Name: LCD County Map
 * Description: Interactive GIS map for Lewis County voting precincts
 * Version: 1.0.0
 * Author: LCD
 */

if (!defined('ABSPATH')) {
    exit;
}

class LCD_County_Map {
    private static $instance = null;
    private $plugin_dir;
    private $gis_dir;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->plugin_dir = plugin_dir_path(__FILE__);
        $this->gis_dir = $this->plugin_dir . 'gis-precincts/';
        
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_lcd_map_upload', array($this, 'handle_file_upload'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function init() {
        add_shortcode('lcd_precinct_map', array($this, 'render_map'));
        add_shortcode('lcd_election_map', array($this, 'render_election_map'));
        
        // Create necessary directories if they don't exist
        $this->create_directories();

        // Initialize election integration if available
        require_once plugin_dir_path(__FILE__) . 'includes/class-election-integration.php';
        LCD_County_Map_Election_Integration::get_instance();
    }

    private function create_directories() {
        $dirs = array(
            $this->gis_dir,
            $this->gis_dir . 'precincts_ref/',
            $this->gis_dir . 'voting_ref/'
        );

        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                $created = wp_mkdir_p($dir);
                if (!$created) {
                    error_log('LCD County Map: Failed to create directory: ' . $dir);
                }
            }
            
            // Ensure directory is writable
            if (!is_writable($dir)) {
                if (!chmod($dir, 0755)) {
                    error_log('LCD County Map: Failed to set permissions on directory: ' . $dir);
                }
            }
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            'LCD County Map Settings',
            'County Map',
            'manage_options',
            'lcd-county-map',
            array($this, 'render_admin_page'),
            'dashicons-location-alt',
            30
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $precincts_file = $this->gis_dir . 'precincts.zip';
        $voting_file = $this->gis_dir . 'voting.zip';
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php
            if (isset($_GET['updated'])) {
                echo '<div class="notice notice-success"><p>Files updated successfully!</p></div>';
            }
            if (isset($_GET['error'])) {
                echo '<div class="notice notice-error"><p>' . esc_html($_GET['error']) . '</p></div>';
            }
            ?>

            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('lcd_map_file_upload', 'lcd_map_nonce'); ?>
                <input type="hidden" name="action" value="lcd_map_upload">
                
                <h2>Upload GIS Data Files</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="precincts_file">Precincts Shapefile (ZIP)</label>
                        </th>
                        <td>
                            <input type="file" name="precincts_file" id="precincts_file" accept=".zip">
                            <?php if (file_exists($precincts_file)): ?>
                                <p class="description">
                                    Current file: precincts.zip (<?php echo date('Y-m-d H:i:s', filemtime($precincts_file)); ?>)
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="voting_file">Voting Data Shapefile (ZIP)</label>
                        </th>
                        <td>
                            <input type="file" name="voting_file" id="voting_file" accept=".zip">
                            <?php if (file_exists($voting_file)): ?>
                                <p class="description">
                                    Current file: voting.zip (<?php echo date('Y-m-d H:i:s', filemtime($voting_file)); ?>)
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Upload Files'); ?>
            </form>
        </div>
        <?php
    }

    public function handle_file_upload() {
        if (!isset($_POST['lcd_map_nonce']) || !wp_verify_nonce($_POST['lcd_map_nonce'], 'lcd_map_file_upload')) {
            error_log('LCD County Map: Nonce verification failed');
            return;
        }

        if (!current_user_can('manage_options')) {
            error_log('LCD County Map: User does not have required capabilities');
            return;
        }

        $uploaded = false;
        $error = '';

        error_log('LCD County Map: Starting file upload process');

        // Handle precincts file upload
        if (!empty($_FILES['precincts_file']['name'])) {
            error_log('LCD County Map: Processing precincts file');
            $result = $this->process_uploaded_file('precincts_file', 'precincts.zip', 'precincts_ref');
            if (is_wp_error($result)) {
                $error = $result->get_error_message();
                error_log('LCD County Map: Precincts file error - ' . $error);
            } else {
                $uploaded = true;
                error_log('LCD County Map: Precincts file uploaded successfully');
            }
        }

        // Handle voting file upload
        if (!empty($_FILES['voting_file']['name'])) {
            error_log('LCD County Map: Processing voting file');
            $result = $this->process_uploaded_file('voting_file', 'voting.zip', 'voting_ref');
            if (is_wp_error($result)) {
                $error = $result->get_error_message();
                error_log('LCD County Map: Voting file error - ' . $error);
            } else {
                $uploaded = true;
                error_log('LCD County Map: Voting file uploaded successfully');
            }
        }

        if ($uploaded) {
            $redirect = add_query_arg('updated', '1', admin_url('admin.php?page=lcd-county-map'));
            if ($error) {
                $redirect = add_query_arg('error', urlencode($error), $redirect);
            }
            error_log('LCD County Map: Redirecting after upload - ' . $redirect);
            wp_redirect($redirect);
            exit;
        }
    }

    private function process_uploaded_file($file_key, $target_filename, $ref_folder) {
        if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
            error_log('LCD County Map: File upload failed - $_FILES error: ' . 
                     (isset($_FILES[$file_key]) ? $_FILES[$file_key]['error'] : 'File not set'));
            return new WP_Error('upload_error', 'File upload failed');
        }

        $uploaded_file = $_FILES[$file_key]['tmp_name'];
        $target_path = $this->gis_dir . $target_filename;
        $ref_path = $this->gis_dir . $ref_folder . '/';

        error_log('LCD County Map: Processing upload - Target path: ' . $target_path);

        // Verify it's a ZIP file
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $uploaded_file);
        finfo_close($finfo);

        if ($mime_type !== 'application/zip') {
            error_log('LCD County Map: Invalid file type - ' . $mime_type);
            return new WP_Error('invalid_file', 'Uploaded file must be a ZIP archive');
        }

        // Ensure target directory exists and is writable
        if (!file_exists($this->gis_dir)) {
            wp_mkdir_p($this->gis_dir);
        }
        if (!is_writable($this->gis_dir)) {
            chmod($this->gis_dir, 0755);
        }

        // Move the uploaded file to the target location
        if (!move_uploaded_file($uploaded_file, $target_path)) {
            $error = error_get_last();
            error_log('LCD County Map: Failed to move uploaded file - ' . 
                     ($error ? $error['message'] : 'Unknown error'));
            return new WP_Error('move_error', 'Failed to save uploaded file');
        }

        error_log('LCD County Map: File moved successfully to ' . $target_path);

        // Clear the reference directory
        $this->clear_directory($ref_path);

        // Ensure reference directory exists and is writable
        if (!file_exists($ref_path)) {
            wp_mkdir_p($ref_path);
        }
        if (!is_writable($ref_path)) {
            chmod($ref_path, 0755);
        }

        // Extract ZIP to reference directory
        $zip = new ZipArchive;
        $zip_result = $zip->open($target_path);
        if ($zip_result === TRUE) {
            $extract_result = $zip->extractTo($ref_path);
            $zip->close();
            
            if ($extract_result) {
                error_log('LCD County Map: ZIP extracted successfully to ' . $ref_path);
                return true;
            } else {
                error_log('LCD County Map: Failed to extract ZIP to ' . $ref_path);
                return new WP_Error('extract_error', 'Failed to extract ZIP file');
            }
        } else {
            error_log('LCD County Map: Failed to open ZIP file - Error code: ' . $zip_result);
            return new WP_Error('extract_error', 'Failed to open ZIP file');
        }
    }

    private function clear_directory($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . $file;
            is_dir($path) ? $this->clear_directory($path) : unlink($path);
        }
    }

    public function enqueue_scripts() {
        // Enqueue Leaflet CSS and JS
        wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet@1.7.1/dist/leaflet.css');
        wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet@1.7.1/dist/leaflet.js', array(), '1.7.1', true);
        
        // Enqueue Shapefile plugin
        wp_enqueue_script('shp', plugins_url('assets/js/shp.min.js', __FILE__), array(), '1.0.0', true);
        wp_enqueue_script('leaflet-shpfile', plugins_url('assets/js/leaflet.shpfile.js', __FILE__), array('leaflet', 'shp'), '1.0.0', true);
        
        // Enqueue our custom map script and styles
        wp_enqueue_style('lcd-map-style', plugins_url('assets/css/map-style.css', __FILE__));
        wp_enqueue_script('lcd-map-script', plugins_url('assets/js/map.js', __FILE__), array('leaflet', 'leaflet-shpfile'), '1.0.0', true);
        
        // Localize script with shapefile paths and make layers accessible
        wp_add_inline_script('lcd-map-script', '
            // Store layers globally for other plugins to access
            var lcdMapLayers = {
                boundary: null,
                voting: null
            };
            
            // Hook into layer creation
            document.addEventListener("lcdMapLayersCreated", function(e) {
                lcdMapLayers.boundary = e.detail.boundaryLayer;
                lcdMapLayers.voting = e.detail.votingLayer;
            });
        ', 'before');
        
        wp_localize_script('lcd-map-script', 'lcdMapData', array(
            'boundaryPath' => plugins_url('gis-precincts/precincts.zip', __FILE__),
            'votingDataPath' => plugins_url('gis-precincts/voting.zip', __FILE__)
        ));
    }

    public function render_map($atts) {
        $config = shortcode_atts(array(
            'height' => '600px',
            'width' => '100%'
        ), $atts);

        // Allow other plugins to modify the configuration
        $config = apply_filters('lcd_county_map_config', $config, $atts);

        // Prepare map data
        $map_data = array(
            'boundaryPath' => plugins_url('gis-precincts/precincts_ref/precincts.shp', __FILE__),
            'votingDataPath' => plugins_url('gis-precincts/voting_ref/voting.shp', __FILE__)
        );

        // Allow other plugins to extend the map data
        $map_data = apply_filters('lcd_county_map_data', $map_data, $config);

        // Localize the script with our data
        wp_localize_script('lcd-county-map-js', 'lcdMapData', $map_data);

        return sprintf(
            '<div id="lcd-precinct-map" style="height: %s; width: %s;"></div>',
            esc_attr($config['height']),
            esc_attr($config['width'])
        );
    }

    public function render_election_map($atts) {
        // Check if election plugin is active
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        if (!is_plugin_active('lcd-election-results/lcd-election-results.php')) {
            return '<div class="lcd-map-error">This map requires the LCD Election Results plugin to be active.</div>';
        }

        // Merge with defaults
        $atts = shortcode_atts(array(
            'width' => '100%',
            'height' => '600px',
        ), $atts);

        // Force election mode
        $atts['show_election_data'] = true;

        // Return the map with election mode enabled
        return $this->render_map($atts);
    }
}

// Initialize the plugin
LCD_County_Map::get_instance(); 