<?php
/**
 * Plugin Name: WP Taxonomy Category Synchronization
 * Description: Designed for WP Job Manager and the Cariera theme, this plugin synchronizes taxonomy categories across 'resume_category', 'company_category', 'job_listing_category', including handling of specific metadata fields like 'cariera_background_image', 'cariera_image_icon', 'cariera_font_icon'. Ensures metadata is correctly copied during synchronization.
 * Version: 1.0
 * Author: Apollo Ventures LLC
 */

// Utility function to prevent self-triggering of hooks
function is_sync_process() {
    return defined('DOING_SYNC') && DOING_SYNC;
}

// Hook into WP All Import action to synchronize term metadata after import
function handle_wp_all_import_saved_term($term_id, $tx_name, $xml_node, $is_update) {
    if (!is_sync_process() && in_array($tx_name, ['resume_category', 'company_category', 'job_listing_category'])) {
        sync_term_metadata_after_import($term_id, $tx_name);
    }
}
add_action('pmxi_saved_term', 'handle_wp_all_import_saved_term', 10, 4);

// Synchronize term metadata after import
function sync_term_metadata_after_import($term_id, $taxonomy) {
    define('DOING_SYNC', true);
    
    $meta_keys = ['cariera_background_image']; // Extend this array with any other meta keys you need to sync
    $other_taxonomies = array_diff(['resume_category', 'company_category', 'job_listing_category'], [$taxonomy]);
    
    foreach ($meta_keys as $meta_key) {
        $meta_value = get_term_meta($term_id, $meta_key, true);
        if (!empty($meta_value)) {
            foreach ($other_taxonomies as $other_taxonomy) {
                // Attempt to find a matching term in the other taxonomy by name
                $term = get_term($term_id, $taxonomy);
                if ($term && !is_wp_error($term)) {
                    $other_term = get_term_by('name', $term->name, $other_taxonomy);
                    if ($other_term && !is_wp_error($other_term)) {
                        update_term_meta($other_term->term_id, $meta_key, $meta_value);
                    }
                }
            }
        }
    }
    
    define('DOING_SYNC', false);
}

// Centralized Metadata Management Functions
function set_synced_term_id($term_id, $target_taxonomy, $synced_term_id) {
    return update_term_meta($term_id, 'synced_term_id_'.$target_taxonomy, $synced_term_id);
}

function get_synced_term_id($term_id, $source_taxonomy) {
    return get_term_meta($term_id, 'synced_term_id_'.$source_taxonomy, true);
}

// Synchronize term creation with additional metadata
function sync_term_creation($term_id, $tt_id, $taxonomy) {
    if (in_array($taxonomy, ['resume_category', 'company_category', 'job_listing_category']) && !is_sync_process()) {
        if (!defined('DOING_SYNC')) {
            define('DOING_SYNC', true);
            $term = get_term($term_id, $taxonomy);

            if (is_wp_error($term) || !$term) {
                define('DOING_SYNC', false);
                return;
            }

            $unique_id = uniqid('sync_');
            update_term_meta($term_id, 'sync_unique_id', $unique_id);

            $other_taxonomies = array_diff(['resume_category', 'company_category', 'job_listing_category'], [$taxonomy]);
            $synced_terms = [$taxonomy => $term_id];

            foreach ($other_taxonomies as $other_taxonomy) {
                $other_term = get_term_by('name', $term->name, $other_taxonomy);
                if (!$other_term) {
                    $new_term = wp_insert_term($term->name, $other_taxonomy, array('description' => $term->description, 'slug' => $term->slug));
                    if (!is_wp_error($new_term)) {
                        $synced_terms[$other_taxonomy] = $new_term['term_id'];
                        update_term_meta($new_term['term_id'], 'sync_unique_id', $unique_id);
                        sync_additional_metadata($term_id, $new_term['term_id'], $taxonomy);
                    } else {
                        error_log('sync_term_creation: Error inserting term into ' . $other_taxonomy . ' - ' . $new_term->get_error_message());
                    }
                } else {
                    $synced_terms[$other_taxonomy] = $other_term->term_id;
                    update_term_meta($other_term->term_id, 'sync_unique_id', $unique_id);
                    sync_additional_metadata($term_id, $other_term->term_id, $taxonomy);
                }
            }

            // Set the synced_term_id metadata for each term
            foreach ($synced_terms as $synced_taxonomy => $synced_term_id) {
                foreach ($other_taxonomies as $other_taxonomy) {
                    if ($synced_taxonomy != $other_taxonomy) {
                        set_synced_term_id($synced_term_id, $other_taxonomy, $synced_terms[$other_taxonomy]);
                    }
                }
                // Also set the reference back to the original term
                if ($synced_taxonomy != $taxonomy) {
                    set_synced_term_id($synced_term_id, $taxonomy, $term_id);
                }
            }

            define('DOING_SYNC', false);
        }
    }
}
add_action('created_term', 'sync_term_creation', 10, 3);

// Synchronize additional metadata with debugging
function sync_additional_metadata($source_term_id, $target_term_id, $source_taxonomy) {
    $metadata_keys = ['cariera_background_image', 'cariera_image_icon', 'cariera_font_icon'];

    foreach ($metadata_keys as $meta_key) {
        $meta_value = get_term_meta($source_term_id, $meta_key, true);

        // Debug: Log the retrieved meta value
        error_log("Retrieving '{$meta_key}' for term {$source_term_id} in {$source_taxonomy}: " . var_export($meta_value, true));

        if (!empty($meta_value)) {
            // Handle potentially serialized data
            $meta_value_to_save = is_array($meta_value) ? serialize($meta_value) : $meta_value;
            update_term_meta($target_term_id, $meta_key, $meta_value_to_save);

            // Debug: Confirm the meta was updated
            $updated_meta_value = get_term_meta($target_term_id, $meta_key, true);
            error_log("Updated '{$meta_key}' for term {$target_term_id}: " . var_export($updated_meta_value, true));
        } else {
            // Optionally handle the case where no value is present
            error_log("No value for '{$meta_key}' in source term {$source_term_id} from {$source_taxonomy}");
        }
    }
}

// Synchronize term updates with more rigorous logic, including additional metadata
function sync_term_update($term_id, $tt_id, $taxonomy) {
    if (!in_array($taxonomy, ['resume_category', 'company_category', 'job_listing_category'])) {
        return;
    }

    remove_action('edited_term', 'sync_term_update', 10);

    $term = get_term($term_id, $taxonomy);
    if (is_wp_error($term) || !$term) {
        error_log('sync_term_update: Failed to retrieve term.');
        add_action('edited_term', 'sync_term_update', 10, 3);
        return;
    }

    $unique_id = get_term_meta($term_id, 'sync_unique_id', true);
    $other_taxonomies = array_diff(['resume_category', 'company_category', 'job_listing_category'], [$taxonomy]);

    foreach ($other_taxonomies as $other_taxonomy) {
        $other_term_id = get_synced_term_id($term_id, $other_taxonomy);
        if (!$other_term_id) {
            $terms = get_terms(['taxonomy' => $other_taxonomy, 'hide_empty' => false, 'meta_key' => 'sync_unique_id', 'meta_value' => $unique_id]);
            $other_term_id = (!empty($terms) && !is_wp_error($terms)) ? $terms[0]->term_id : null;
        }

        if ($other_term_id) {
            $term_data = ['name' => $term->name, 'description' => $term->description, 'slug' => $term->slug];
            $updated_term = wp_update_term($other_term_id, $other_taxonomy, $term_data);
            if (is_wp_error($updated_term)) {
                error_log("sync_term_update: Error updating term in {$other_taxonomy} - " . $updated_term->get_error_message());
            } else {
                sync_additional_metadata($term_id, $other_term_id, $taxonomy);
            }
        }
    }

    add_action('edited_term', 'sync_term_update', 10, 3);
}
add_action('edited_term', 'sync_term_update', 10, 3);

// Synchronize term deletion, including cleanup of additional metadata
function sync_term_deletion($term_id, $tt_id, $taxonomy) {
    static $in_sync = false;

    if ($in_sync) {
        return; // Prevent recursion
    }

    if (!in_array($taxonomy, ['resume_category', 'company_category', 'job_listing_category'])) {
        return;
    }

    $in_sync = true;

    $unique_id = get_term_meta($term_id, 'sync_unique_id', true);
    $other_taxonomies = array_diff(['resume_category', 'company_category', 'job_listing_category'], [$taxonomy]);

    foreach ($other_taxonomies as $other_taxonomy) {
        $other_term_id = get_synced_term_id($term_id, $other_taxonomy);
        if (!$other_term_id) {
            $terms = get_terms(['taxonomy' => $other_taxonomy, 'hide_empty' => false, 'meta_key' => 'sync_unique_id', 'meta_value' => $unique_id]);
            $other_term_id = (!empty($terms) && !is_wp_error($terms)) ? $terms[0]->term_id : null;
        }

        if ($other_term_id && term_exists($other_term_id, $other_taxonomy)) {
            wp_delete_term($other_term_id, $other_taxonomy);
        }
    }

    $in_sync = false;
}
add_action('delete_term', 'sync_term_deletion', 10, 3);

// Metadata logic
function display_custom_term_metadata($term) {
    // Check if the term belongs to one of the synchronized taxonomies
    if (in_array($term->taxonomy, ['resume_category', 'company_category', 'job_listing_category'])) {
        // Fetch all metadata for the term
        $term_meta = get_term_meta($term->term_id);

        // Check if metadata exists
        if (!empty($term_meta)) {
            // Display the metadata
            echo '<tr class="form-field term-group-wrap">';
            echo '<th scope="row"><label>Custom Term Metadata</label></th>';
            echo '<td>';
            echo '<pre>' . esc_html(print_r($term_meta, true)) . '</pre>';
            echo '</td>';
            echo '</tr>';
        } else {
            // Message if no metadata is found
            echo '<tr class="form-field term-group-wrap">';
            echo '<th scope="row"><label>Custom Term Metadata</label></th>';
            echo '<td>';
            echo '<p>No custom metadata found for this term.</p>';
            echo '</td>';
            echo '</tr>';
        }
    }
}
// Adding the function to the edit form fields for each taxonomy
add_action('resume_category_edit_form_fields', 'display_custom_term_metadata', 10, 1);
add_action('company_category_edit_form_fields', 'display_custom_term_metadata', 10, 1);
add_action('job_listing_category_edit_form_fields', 'display_custom_term_metadata', 10, 1);

// Enqueue admin scripts and styles
function enqueue_admin_scripts_and_styles() {
    wp_enqueue_script('my-custom-admin-script', plugin_dir_url(__FILE__) . 'js/admin-script.js', array('jquery'), '1.0.0', true);
    wp_enqueue_style('my-custom-admin-style', plugin_dir_url(__FILE__) . 'css/admin-style.css', array(), '1.0.0');
}
add_action('admin_enqueue_scripts', 'enqueue_admin_scripts_and_styles');