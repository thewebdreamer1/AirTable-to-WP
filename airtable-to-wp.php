global $airtable_records;
$airtable_records = [];
function airtable_offset_check($main_json,$offset_list) {
    global $airtable_records;
    if(!empty($offset_list)) {
        $offset_json = $main_json."&offset=".$offset_list;
        $offset_response = file_get_contents($offset_json);
        $offset_decode = json_decode($offset_response);
        $offset_records = $offset_decode->records;
        $offset_offset = property_exists($offset_decode, 'offset') ? $offset_decode->offset : null;
        foreach($offset_records as $record) {
            array_push($airtable_records,$record);
        }
        if(!empty($offset_offset)) {
            airtable_offset_check($main_json,$offset_offset);
        }
    }
}

function upsert_records() {
    global $wpdb;

    WP_CLI::line('Connecting to AirTable API...');

    $api_key = [Put API key here];
    $json = 'https://api.airtable.com/[AirTable version number e.g. v0]/[AirTable App ID]/[AirTable App Desired Table e.g.videos]?api_key='.$api_key;
    $response = file_get_contents($json);
    $my_decode = json_decode($response);
    $initial_records = $my_decode->records;
    global $airtable_records;
    foreach($initial_records as $record) {
        array_push($airtable_records,$record);
    }
    $offset = property_exists($my_decode, 'offset') ? $my_decode->offset : null;
    airtable_offset_check($json,$offset);

    $progress_bar = WP_CLI\Utils\make_progress_bar('Upserting Records...', count($airtable_records));

    $delete_args = array('post_type' => '[Insert custom post type or post]');
    $get_all_posts = new WP_Query($delete_args);
    $active_posts_array = array();

    foreach ($airtable_records as $object) {
        $fields = $object->fields;
        
        //Make sure you add a status field to Air Table with ACTIVE and INACTIVE states
        $status = property_exists($fields, 'status') ? $fields->status : null;

        //Insert each of the desired fields from AirTable into variables using the below one as an example.
        $airtable_field = property_exists($fields, 'airtable_field') ? $fields->airtable_field : null;

        // Check if already exists 
        $args = array(
            'post_type' => '[Insert custom post type or post]',
            'meta_query' => array(
                array(
                    'key' => 'airtable_post_id',
                    'value' => $object->id,
                    'compare' => '=',
                )
            )
        );
        $get_post = new WP_Query($args);
        if ($get_post->have_posts()) {
            while ($get_post->have_posts()) {
                $get_post->the_post();
                $update_post_id = get_the_ID();
                array_push($active_posts_array,$update_post_id);

                if(isset($status) && $status == 'ACTIVE' && !empty($name)) {
                  // Update post title
                  $current_post = array(
                    'ID'           => $update_post_id,
                    'post_title'   => $name
                  );
                  wp_update_post( $current_post );

                    // Update meta values
                    
                    // Textfield meta data update
                    update_post_meta( $update_post_id, 'airtable_field', $airtable_field );

                    // Textarea meta data update
                    if(!empty($airtable_field)) {
                        $collection_str = implode( "\n", $airtable_field );
                        update_post_meta( $update_post_id, 'airtable_field_ids', $collection_str );
                    } else {
                        update_post_meta( $update_post_id, 'airtable_field_ids', '' );
                    }

                    // Repeater field meta data update
                    if(!empty($airtable_field)) {
                        update_post_meta( $update_post_id, 'airtable_field_repeater', count($airtable_field) );
                        $airtable_field_count = 0;
                        $wpdb->query( "DELETE FROM wp_postmeta WHERE post_id = $update_post_id AND meta_key LIKE '%airtable_field_repeater_%'" );
                        foreach($airtable_field as $item) {
                            add_post_meta( $update_post_id, 'airtable_field_repeater_'.$airtable_field_count.'_repeater_item', $item );
                            add_post_meta( $update_post_id, '_airtable_field_repeater_'.$airtable_field_count.'_repeater_item', '[field id of repeater]' );
                            $airtable_field_count++;
                        }
                    } else {
                        update_post_meta( $update_post_id, 'airtable_field_repeater', '' );
                        $wpdb->query( "DELETE FROM wp_postmeta WHERE post_id = $update_post_id AND meta_key LIKE '%airtable_field_repeater_%'" );
                    }

                } else {
                    $delete = $wpdb->query(
                        "DELETE
                        p,pm
                        FROM wp_posts p
                        JOIN wp_postmeta pm
                        ON pm.post_id = p.id
                        WHERE p.post_type = '[Insert custom post type or post]'
                        AND p.id = $update_post_id"
                    );
                }
            }
        } else {
            if(isset($status) && $status == 'ACTIVE' && !empty($name)) {
                //Insert post data
                $new_post = array(
                    'post_title' => $name,
                    'post_content' => 'AirTable Record - '.$name,
                    'post_status' => 'publish',
                    'post_author' => 1,
                    'post_type' => '[Insert custom post type or post]'
                );
                // Insert post
                $post_id = wp_insert_post($new_post);
                // Insert post meta if available
                add_post_meta( $post_id, 'video_id', $object->id );
                add_post_meta( $post_id, '_video_id', 'field_5dc0a711f1107' );

                if(!empty($collection)) {
                    $collection_str = implode( "\n", $collection );
                    add_post_meta( $post_id, 'collection_ids', $collection_str );
                }
                add_post_meta( $post_id, '_collection_ids', 'field_5dc5d210151b0' );

                if(!empty($airtable_field)) {
                    add_post_meta( $post_id, 'airtable_field_repeater', count($airtable_field) );
                    add_post_meta( $post_id, '_airtable_field_repeater', '[field id of repeater]' );
                    $airtable_field_count = 0;
                    foreach($airtable_field as $item) {
                        add_post_meta( $post_id, 'airtable_field_repeater_'.$airtable_field_count.'_repeater_item', $item );
                        add_post_meta( $post_id, '_airtable_field_repeater_'.$airtable_field_count.'_repeater_item', '[field id of repeater item]' );
                        $airtable_field_count++;
                    }
                }
            }
        }

        $progress_bar->tick();
    }

    if ($get_all_posts->have_posts()) {
        while ($get_all_posts->have_posts()) {
            $get_all_posts->the_post();
            $delete_post_id = get_the_ID();
            if(!in_array($delete_post_id,$active_posts_array)) {
                $delete = $wpdb->query(
                    "DELETE
                    p,pm
                    FROM wp_posts p
                    JOIN wp_postmeta pm
                    ON pm.post_id = p.id
                    WHERE p.post_type = '[Insert custom post type or post]'
                    AND p.id = $delete_post_id"
                );
            }
        }
    }

    $progress_bar->finish();
    WP_CLI::success( 'AirTable Records successfully updated.' );
}

//If you want to tap into WP_CLI to execute this command
if (defined('WP_CLI') && WP_CLI) {
  WP_CLI::add_command('upsert_records', 'upsert_records');
}
