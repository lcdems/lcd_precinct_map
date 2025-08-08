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
        add_action('admin_post_lcd_pco_save', array($this, 'handle_pco_save'));
        add_action('admin_post_lcd_pco_delete', array($this, 'handle_pco_delete'));
        add_action('admin_post_lcd_init_pco_table', array($this, 'handle_init_pco_table'));
        add_action('wp_ajax_lcd_get_precincts', array($this, 'ajax_get_precincts'));
        add_action('wp_ajax_lcd_contact_pco', array($this, 'handle_contact_pco'));
        add_action('wp_ajax_nopriv_lcd_contact_pco', array($this, 'handle_contact_pco'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Create database table on activation
        register_activation_hook(__FILE__, array($this, 'create_pco_table'));
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

        // Initialize PCO table if it doesn't exist
        $this->create_pco_table();
        
        $current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'upload';
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php
            if (isset($_GET['updated'])) {
                echo '<div class="notice notice-success"><p>';
                if ($current_tab === 'pco') {
                    echo 'PCO information updated successfully!';
                } else {
                    echo 'Files updated successfully!';
                }
                echo '</p></div>';
            }
            if (isset($_GET['initialized'])) {
                echo '<div class="notice notice-success"><p>PCO database initialized successfully!</p></div>';
            }
            if (isset($_GET['error'])) {
                $error_message = $_GET['error'];
                if ($error_message === 'save_failed') {
                    $error_message = 'Failed to save PCO information.';
                } elseif ($error_message === 'delete_failed') {
                    $error_message = 'Failed to delete PCO information.';
                } elseif ($error_message === 'invalid_precinct') {
                    $error_message = 'Invalid precinct number. Operations on Precinct #0 are not allowed.';
                } else {
                    $error_message = esc_html($error_message);
                }
                echo '<div class="notice notice-error"><p>' . $error_message . '</p></div>';
            }
            ?>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo admin_url('admin.php?page=lcd-county-map&tab=upload'); ?>" 
                   class="nav-tab <?php echo $current_tab === 'upload' ? 'nav-tab-active' : ''; ?>">
                    GIS Data Upload
                </a>
                <a href="<?php echo admin_url('admin.php?page=lcd-county-map&tab=pco'); ?>" 
                   class="nav-tab <?php echo $current_tab === 'pco' ? 'nav-tab-active' : ''; ?>">
                    PCO Management
                </a>
            </h2>

            <?php if ($current_tab === 'upload'): ?>
                <?php $this->render_upload_tab(); ?>
            <?php elseif ($current_tab === 'pco'): ?>
                <?php $this->render_pco_tab(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_upload_tab() {
        $precincts_file = $this->gis_dir . 'precincts.zip';
        $voting_file = $this->gis_dir . 'voting.zip';
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
        <?php
    }

    private function render_pco_tab() {
        // Sync precincts to ensure we have the latest data
        $this->sync_precincts_with_pco_table();
        
        $pco_data = $this->get_pco_data();
        ?>
        <div class="lcd-pco-management">
            <h2>Precinct Committee Officer (PCO) Management</h2>
            
            <?php if (!empty($pco_data['active'])): ?>
                <h3>Active Precincts</h3>
                <div class="pco-list-container">
                    <?php foreach ($pco_data['active'] as $pco): ?>
                        <div class="pco-item" data-precinct="<?php echo esc_attr($pco->precinct_number); ?>">
                            <div class="pco-header">
                                <h4>Precinct #<?php echo esc_html($pco->precinct_number); ?> 
                                    <?php if ($pco->precinct_name): ?>
                                        - <?php echo esc_html($pco->precinct_name); ?>
                                    <?php endif; ?>
                                </h4>
                                <button type="button" class="button pco-edit-btn" data-precinct="<?php echo esc_attr($pco->precinct_number); ?>">
                                    <?php echo ($pco->pco_name || $pco->pco_email) ? 'Edit' : 'Add PCO'; ?>
                                </button>
                            </div>
                            
                            <div class="pco-display" id="pco-display-<?php echo esc_attr($pco->precinct_number); ?>">
                                <?php if ($pco->pco_name || $pco->pco_email): ?>
                                    <p><strong>PCO Name:</strong> <?php echo esc_html($pco->pco_name ?: 'Not set'); ?></p>
                                    <p><strong>PCO Email:</strong> <?php echo esc_html($pco->pco_email ?: 'Not set'); ?></p>
                                <?php else: ?>
                                    <p class="no-pco">No PCO assigned</p>
                                <?php endif; ?>
                            </div>
                            
                            <div class="pco-edit-form" id="pco-form-<?php echo esc_attr($pco->precinct_number); ?>" style="display: none;">
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <?php wp_nonce_field('lcd_pco_save', 'lcd_pco_nonce'); ?>
                                    <input type="hidden" name="action" value="lcd_pco_save">
                                    <input type="hidden" name="precinct_number" value="<?php echo esc_attr($pco->precinct_number); ?>">
                                    
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">
                                                <label for="pco_name_<?php echo esc_attr($pco->precinct_number); ?>">PCO Name</label>
                                            </th>
                                            <td>
                                                <input type="text" 
                                                       name="pco_name" 
                                                       id="pco_name_<?php echo esc_attr($pco->precinct_number); ?>"
                                                       value="<?php echo esc_attr($pco->pco_name); ?>"
                                                       class="regular-text">
                                            </td>
                                        </tr>
                                        <tr>
                                            <th scope="row">
                                                <label for="pco_email_<?php echo esc_attr($pco->precinct_number); ?>">PCO Email</label>
                                            </th>
                                            <td>
                                                <input type="email" 
                                                       name="pco_email" 
                                                       id="pco_email_<?php echo esc_attr($pco->precinct_number); ?>"
                                                       value="<?php echo esc_attr($pco->pco_email); ?>"
                                                       class="regular-text">
                                            </td>
                                        </tr>
                                    </table>
                                    
                                    <div class="pco-form-actions">
                                        <?php submit_button('Save PCO Info', 'primary', 'submit', false); ?>
                                        <button type="button" class="button pco-cancel-btn" data-precinct="<?php echo esc_attr($pco->precinct_number); ?>">Cancel</button>
                                        <?php if ($pco->pco_name || $pco->pco_email): ?>
                                            <button type="button" class="button button-link-delete pco-delete-btn" data-precinct="<?php echo esc_attr($pco->precinct_number); ?>">
                                                Clear PCO Info
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-precincts-container">
                    <h3>No Precincts Found</h3>
                    <p>No precincts were found in the database. This could be because:</p>
                    <ul>
                        <li>No precinct shapefile has been uploaded yet</li>
                        <li>The PCO database table needs to be initialized</li>
                        <li>There was an error reading the shapefile data</li>
                    </ul>
                    
                    <div style="margin-top: 20px;">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline-block;">
                            <?php wp_nonce_field('lcd_init_pco_table', 'lcd_init_nonce'); ?>
                            <input type="hidden" name="action" value="lcd_init_pco_table">
                            <?php submit_button('Initialize PCO Database', 'primary', 'submit', false); ?>
                        </form>
                        
                        <a href="<?php echo admin_url('admin.php?page=lcd-county-map&tab=upload'); ?>" class="button button-secondary" style="margin-left: 10px;">
                            Upload Precincts File
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($pco_data['orphaned'])): ?>
                <h3>Orphaned Precincts</h3>
                <p class="description">These precincts have PCO information but are no longer in the current shapefile data:</p>
                <div class="pco-orphaned-container">
                    <?php foreach ($pco_data['orphaned'] as $pco): ?>
                        <div class="pco-item orphaned" data-precinct="<?php echo esc_attr($pco->precinct_number); ?>">
                            <div class="pco-header">
                                <h4>Precinct #<?php echo esc_html($pco->precinct_number); ?> 
                                    <?php if ($pco->precinct_name): ?>
                                        - <?php echo esc_html($pco->precinct_name); ?>
                                    <?php endif; ?>
                                    <span class="orphaned-label">(Orphaned)</span>
                                </h4>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                                    <?php wp_nonce_field('lcd_pco_delete', 'lcd_pco_nonce'); ?>
                                    <input type="hidden" name="action" value="lcd_pco_delete">
                                    <input type="hidden" name="precinct_number" value="<?php echo esc_attr($pco->precinct_number); ?>">
                                    <button type="submit" class="button button-link-delete" onclick="return confirm('Are you sure you want to delete this orphaned PCO record?');">
                                        Delete
                                    </button>
                                </form>
                            </div>
                            
                            <div class="pco-display">
                                <p><strong>PCO Name:</strong> <?php echo esc_html($pco->pco_name ?: 'Not set'); ?></p>
                                <p><strong>PCO Email:</strong> <?php echo esc_html($pco->pco_email ?: 'Not set'); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
            // Sync precincts with PCO table after successful upload
            $this->sync_precincts_with_pco_table();
            
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

    public function create_pco_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'lcd_precinct_pco';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            precinct_number varchar(20) NOT NULL,
            precinct_name varchar(255) DEFAULT '',
            pco_name varchar(255) DEFAULT '',
            pco_email varchar(255) DEFAULT '',
            is_orphaned tinyint(1) DEFAULT 0,
            created_date datetime DEFAULT CURRENT_TIMESTAMP,
            updated_date datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY precinct_number (precinct_number)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function get_precincts_from_shapefile() {
        $precincts = array();
        $precincts_dbf = $this->gis_dir . 'precincts_ref/precincts.dbf';
        
        if (!file_exists($precincts_dbf)) {
            return $precincts;
        }
        
        require_once($this->plugin_dir . 'includes/shapefile.inc.php');
        
        try {
            $shapefile = new ShapeFile($this->gis_dir . 'precincts_ref/precincts.shp');
            
            while ($record = $shapefile->getRecord()) {
                if (isset($record['dbf']['PRECINCT_N']) && 
                    $record['dbf']['PRECINCT_N'] !== '0' && 
                    $record['dbf']['PRECINCT_N'] !== 0) {
                    $precincts[] = array(
                        'number' => $record['dbf']['PRECINCT_N'],
                        'name' => isset($record['dbf']['PRECINCT']) ? $record['dbf']['PRECINCT'] : ''
                    );
                }
            }
        } catch (Exception $e) {
            error_log('LCD County Map: Error reading shapefile - ' . $e->getMessage());
        }
        
        return $precincts;
    }

    public function sync_precincts_with_pco_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'lcd_precinct_pco';
        $precincts = $this->get_precincts_from_shapefile();
        
        if (empty($precincts)) {
            return;
        }
        
        // Get current precinct numbers from shapefile
        $current_precincts = array_column($precincts, 'number');
        
        // Mark precincts as orphaned if they're not in the current shapefile
        $existing_precincts = $wpdb->get_col("SELECT precinct_number FROM $table_name");
        foreach ($existing_precincts as $existing_precinct) {
            if (!in_array($existing_precinct, $current_precincts)) {
                $wpdb->update(
                    $table_name,
                    array('is_orphaned' => 1),
                    array('precinct_number' => $existing_precinct)
                );
            }
        }
        
        // Add new precincts or update existing ones
        foreach ($precincts as $precinct) {
            // Skip precinct #0 (additional safety check)
            if ($precinct['number'] === '0' || $precinct['number'] === 0) {
                continue;
            }
            
            $wpdb->query($wpdb->prepare("
                INSERT INTO $table_name (precinct_number, precinct_name, is_orphaned) 
                VALUES (%s, %s, 0)
                ON DUPLICATE KEY UPDATE 
                precinct_name = VALUES(precinct_name),
                is_orphaned = 0
            ", $precinct['number'], $precinct['name']));
        }
    }

    public function get_pco_data() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'lcd_precinct_pco';
        
        $active_pcos = $wpdb->get_results("
            SELECT * FROM $table_name 
            WHERE is_orphaned = 0 AND precinct_number != '0' AND precinct_number != 0
            ORDER BY CAST(precinct_number AS UNSIGNED)
        ");
        
        $orphaned_pcos = $wpdb->get_results("
            SELECT * FROM $table_name 
            WHERE is_orphaned = 1 AND (pco_name != '' OR pco_email != '') 
            AND precinct_number != '0' AND precinct_number != 0
            ORDER BY CAST(precinct_number AS UNSIGNED)
        ");
        
        return array(
            'active' => $active_pcos,
            'orphaned' => $orphaned_pcos
        );
    }

    public function handle_pco_save() {
        if (!isset($_POST['lcd_pco_nonce']) || !wp_verify_nonce($_POST['lcd_pco_nonce'], 'lcd_pco_save')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'lcd_precinct_pco';
        
        $precinct_number = sanitize_text_field($_POST['precinct_number']);
        
        // Prevent operations on Precinct #0
        if ($precinct_number === '0' || $precinct_number === 0) {
            wp_redirect(add_query_arg(array('page' => 'lcd-county-map', 'tab' => 'pco', 'error' => 'invalid_precinct'), admin_url('admin.php')));
            exit;
        }
        
        $pco_name = sanitize_text_field($_POST['pco_name']);
        $pco_email = sanitize_email($_POST['pco_email']);
        
        $result = $wpdb->update(
            $table_name,
            array(
                'pco_name' => $pco_name,
                'pco_email' => $pco_email
            ),
            array('precinct_number' => $precinct_number)
        );
        
        if ($result !== false) {
            wp_redirect(add_query_arg(array('page' => 'lcd-county-map', 'tab' => 'pco', 'updated' => '1'), admin_url('admin.php')));
        } else {
            wp_redirect(add_query_arg(array('page' => 'lcd-county-map', 'tab' => 'pco', 'error' => 'save_failed'), admin_url('admin.php')));
        }
        exit;
    }

    public function handle_pco_delete() {
        if (!isset($_POST['lcd_pco_nonce']) || !wp_verify_nonce($_POST['lcd_pco_nonce'], 'lcd_pco_delete')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'lcd_precinct_pco';
        
        $precinct_number = sanitize_text_field($_POST['precinct_number']);
        
        // Prevent operations on Precinct #0
        if ($precinct_number === '0' || $precinct_number === 0) {
            wp_redirect(add_query_arg(array('page' => 'lcd-county-map', 'tab' => 'pco', 'error' => 'invalid_precinct'), admin_url('admin.php')));
            exit;
        }
        
        $result = $wpdb->update(
            $table_name,
            array(
                'pco_name' => '',
                'pco_email' => ''
            ),
            array('precinct_number' => $precinct_number)
        );
        
        if ($result !== false) {
            wp_redirect(add_query_arg(array('page' => 'lcd-county-map', 'tab' => 'pco', 'updated' => '1'), admin_url('admin.php')));
        } else {
            wp_redirect(add_query_arg(array('page' => 'lcd-county-map', 'tab' => 'pco', 'error' => 'delete_failed'), admin_url('admin.php')));
        }
        exit;
    }

    public function handle_init_pco_table() {
        if (!isset($_POST['lcd_init_nonce']) || !wp_verify_nonce($_POST['lcd_init_nonce'], 'lcd_init_pco_table')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $this->create_pco_table();
        $this->sync_precincts_with_pco_table();
        
        wp_redirect(add_query_arg(array('page' => 'lcd-county-map', 'tab' => 'pco', 'initialized' => '1'), admin_url('admin.php')));
        exit;
    }

    public function get_pco_for_precinct($precinct_number) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'lcd_precinct_pco';
        
        return $wpdb->get_row($wpdb->prepare("
            SELECT * FROM $table_name 
            WHERE precinct_number = %s AND is_orphaned = 0
        ", $precinct_number));
    }

    public function get_all_frontend_pco_data() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'lcd_precinct_pco';
        
        $pcos = $wpdb->get_results("
            SELECT precinct_number, precinct_name, pco_name, pco_email 
            FROM $table_name 
            WHERE is_orphaned = 0 AND precinct_number != '0' AND precinct_number != 0
            ORDER BY CAST(precinct_number AS UNSIGNED)
        ");
        
        // Convert to associative array keyed by precinct number
        $pco_data = array();
        foreach ($pcos as $pco) {
            $pco_data[$pco->precinct_number] = array(
                'name' => $pco->pco_name,
                'email' => $pco->pco_email,
                'precinct_name' => $pco->precinct_name,
                'has_pco' => !empty($pco->pco_name) || !empty($pco->pco_email)
            );
        }
        
        return $pco_data;
    }

    public function handle_contact_pco() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'lcd_contact_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Sanitize input
        $precinct_number = sanitize_text_field($_POST['precinct_number']);
        $sender_name = sanitize_text_field($_POST['sender_name']);
        $sender_email = sanitize_email($_POST['sender_email']);
        $message = sanitize_textarea_field($_POST['message']);
        $is_become_pco = isset($_POST['is_become_pco']) && $_POST['is_become_pco'] === 'true';
        
        // Validate required fields
        if (empty($sender_name) || empty($sender_email) || empty($message)) {
            wp_send_json_error('All fields are required');
        }
        
        if (!is_email($sender_email)) {
            wp_send_json_error('Please provide a valid email address');
        }
        
        // Get PCO info for this precinct
        $pco = $this->get_pco_for_precinct($precinct_number);
        
        // Determine recipient email
        $recipient_email = 'chair@lewiscountydemocrats.org';
        $recipient_name = 'Lewis County Democrats Chair';
        
        if ($pco && !empty($pco->pco_email)) {
            $recipient_email = $pco->pco_email;
            $recipient_name = $pco->pco_name ?: 'Precinct Committee Officer';
        }
        
        // Prepare email content with HTML styling
        if ($is_become_pco) {
            $subject = "Interest in Becoming PCO for Precinct #{$precinct_number}";
            $email_type = "PCO Interest";
            $intro_text = "Someone has expressed interest in becoming the PCO for Precinct #{$precinct_number}.";
        } else {
            $subject = "Contact from Precinct #{$precinct_number} Constituent";
            $email_type = "Constituent Contact";
            $intro_text = "You have received a message regarding Precinct #{$precinct_number}.";
        }
        
        $precinct_info = "Precinct #{$precinct_number}";
        if ($pco && $pco->precinct_name) {
            $precinct_info .= " - {$pco->precinct_name}";
        }
        
        // Create HTML email template
        $email_body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>{$subject}</title>
        </head>
        <body style='margin: 0; padding: 0; font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; background: #ffffff;'>
                <!-- Header -->
                <div style='background: #0073aa; color: white; padding: 20px; text-align: center;'>
                    <h1 style='margin: 0; font-size: 24px; font-weight: normal;'>Lewis County Democrats</h1>
                    <p style='margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;'>{$email_type}</p>
                </div>
                
                <!-- Content -->
                <div style='padding: 30px 20px;'>
                    <p style='font-size: 16px; margin: 0 0 20px 0; color: #555;'>{$intro_text}</p>
                    
                    <!-- Contact Details -->
                    <div style='background: #f8f9fa; border-left: 4px solid #0073aa; padding: 20px; margin: 20px 0;'>
                        <h3 style='margin: 0 0 15px 0; color: #0073aa; font-size: 18px;'>Contact Information</h3>
                        <p style='margin: 5px 0; font-size: 15px;'><strong>Name:</strong> {$sender_name}</p>
                        <p style='margin: 5px 0; font-size: 15px;'><strong>Email:</strong> <a href='mailto:{$sender_email}' style='color: #0073aa; text-decoration: none;'>{$sender_email}</a></p>
                        <p style='margin: 5px 0; font-size: 15px;'><strong>Precinct:</strong> {$precinct_info}</p>
                    </div>
                    
                    <!-- Message -->
                    <div style='margin: 20px 0;'>
                        <h3 style='margin: 0 0 10px 0; color: #333; font-size: 16px;'>Message:</h3>
                        <div style='background: #ffffff; border: 1px solid #e0e0e0; border-radius: 4px; padding: 15px; white-space: pre-wrap; font-size: 14px; line-height: 1.5;'>" . nl2br(esc_html($message)) . "</div>
                    </div>
                    
                    <!-- Action Button -->
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='mailto:{$sender_email}?subject=Re: {$subject}' style='background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block; font-weight: bold;'>Reply to {$sender_name}</a>
                    </div>
                </div>
                
                <!-- Footer -->
                <div style='background: #f8f9fa; padding: 20px; text-align: center; border-top: 1px solid #e0e0e0;'>
                    <p style='margin: 0 0 10px 0; font-size: 12px; color: #666;'>
                        This message was sent via the Lewis County Democrats precinct map contact form.
                    </p>
                    <p style='margin: 0; font-size: 12px; color: #888;'>
                        Lewis County Democrats<br>
                        <a href='mailto:chair@lewiscountydemocrats.org' style='color: #0073aa; text-decoration: none;'>chair@lewiscountydemocrats.org</a>
                    </p>
                </div>
            </div>
        </body>
        </html>";
        
        // Set email headers for HTML
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            "From: Lewis County Democrats <noreply@lewiscountydemocrats.org>",
            "Reply-To: {$sender_name} <{$sender_email}>"
        );
        
        // Send email
        $sent = wp_mail($recipient_email, $subject, $email_body, $headers);
        
        if ($sent) {
            wp_send_json_success('Message sent successfully');
        } else {
            wp_send_json_error('Failed to send message. Please try again.');
        }
    }

    public function ajax_get_precincts() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $precincts = $this->get_precincts_from_shapefile();
        wp_send_json_success($precincts);
    }

    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_lcd-county-map' !== $hook) {
            return;
        }
        
        wp_enqueue_style('lcd-county-map-admin', plugins_url('assets/css/admin-style.css', __FILE__));
        wp_enqueue_script('lcd-county-map-admin', plugins_url('assets/js/admin-script.js', __FILE__), array('jquery'), '1.0.0', true);
        
        wp_localize_script('lcd-county-map-admin', 'lcdAdminData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lcd_admin_nonce')
        ));
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
        
        // Get PCO data for frontend
        $pco_data = $this->get_all_frontend_pco_data();
        
        wp_localize_script('lcd-map-script', 'lcdMapData', array(
            'boundaryPath' => plugins_url('gis-precincts/precincts.zip', __FILE__),
            'votingDataPath' => plugins_url('gis-precincts/voting.zip', __FILE__),
            'pcoData' => $pco_data,
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lcd_contact_nonce')
        ));
    }

    public function render_map($atts) {
        $config = shortcode_atts(array(
            'height' => '600px',
            'width' => '100%',
            'show_election_data' => false
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

        $modal_html = $this->get_contact_modal_html();
        
        return sprintf(
            '<div class="lcd-map-container%s" style="height: %s; width: %s;">
                <div class="lcd-precinct-sidebar">
                    <div class="lcd-precinct-search">
                        <div class="search-input-wrapper">
                            <input type="text" id="precinct-search" placeholder="Search precincts...">
                            <button type="button" class="search-clear" aria-label="Clear search">&times;</button>
                        </div>
                    </div>
                    <div class="lcd-precinct-list"></div>
                </div>
                <div id="lcd-precinct-map"></div>
                %s
            </div>',
            $config['show_election_data'] ? ' election-mode' : '',
            esc_attr($config['height']),
            esc_attr($config['width']),
            $modal_html
        );
    }

    public function render_election_map($atts) {
        // Check if election plugin is active by checking for the class
        if (!class_exists('LCD_Election_Results')) {
            return '<div class="lcd-map-error">This map requires the LCD Election Results plugin to be active.</div>';
        }

        // Merge with defaults
        $atts = shortcode_atts(array(
            'width' => '100%',
            'height' => '600px',
            'show_election_data' => true  // Force election mode
        ), $atts);

        // Return the map with election mode enabled
        return $this->render_map($atts);
    }

    private function get_contact_modal_html() {
        return '
        <!-- PCO Contact Modal -->
        <div id="lcd-contact-modal" class="lcd-modal" style="display: none;">
            <div class="lcd-modal-content">
                <div class="lcd-modal-header">
                    <h3 id="lcd-contact-modal-title">Contact PCO</h3>
                    <button type="button" class="lcd-modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="lcd-modal-body">
                    <form id="lcd-contact-form">
                        <input type="hidden" id="contact-precinct-number" name="precinct_number" value="">
                        <input type="hidden" id="contact-is-become-pco" name="is_become_pco" value="false">
                        
                        <div class="lcd-form-group">
                            <label for="contact-sender-name">Your Name *</label>
                            <input type="text" id="contact-sender-name" name="sender_name" required class="lcd-form-control">
                        </div>
                        
                        <div class="lcd-form-group">
                            <label for="contact-sender-email">Your Email *</label>
                            <input type="email" id="contact-sender-email" name="sender_email" required class="lcd-form-control">
                        </div>
                        
                        <div class="lcd-form-group">
                            <label for="contact-message">Message *</label>
                            <textarea id="contact-message" name="message" rows="5" required class="lcd-form-control" placeholder="Enter your message here..."></textarea>
                        </div>
                        
                        <div class="lcd-form-actions">
                            <button type="submit" class="lcd-btn lcd-btn-primary">
                                <span class="button-text">Send Message</span>
                                <span class="button-loading" style="display: none;">Sending...</span>
                            </button>
                            <button type="button" class="lcd-btn lcd-btn-secondary lcd-modal-cancel">Cancel</button>
                        </div>
                    </form>
                    
                    <div id="lcd-contact-success" style="display: none;">
                        <div class="lcd-success-message">
                            <h4>Message Sent!</h4>
                            <p>Your message has been sent successfully. You should receive a response soon.</p>
                            <button type="button" class="lcd-btn lcd-btn-primary lcd-modal-close">Close</button>
                        </div>
                    </div>
                    
                    <div id="lcd-contact-error" style="display: none;">
                        <div class="lcd-error-message">
                            <h4>Error</h4>
                            <p id="lcd-error-text">An error occurred while sending your message.</p>
                            <div class="lcd-form-actions">
                                <button type="button" class="lcd-btn lcd-btn-primary" onclick="document.getElementById(\'lcd-contact-form\').style.display = \'block\'; this.parentElement.parentElement.parentElement.style.display = \'none\';">Try Again</button>
                                <button type="button" class="lcd-btn lcd-btn-secondary lcd-modal-close">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="lcd-modal-backdrop"></div>
        </div>';
    }
}

// Initialize the plugin
LCD_County_Map::get_instance(); 