<?php if (!defined ('ABSPATH')) die('No direct access allowed');

/**
 * WP BackItUp  - Settings View
 *
 * @package WP BackItUp
 * @author  Chris Simmons <chris.simmons@wpbackitup.com>
 * @link    http://www.wpbackitup.com
 *
 */

    $namespace = $this->namespace;
    /* translators: %s: Plugin name */
    $page_title = sprintf(esc_html__('%s Settings', 'wp-backitup'), $this->friendly_name );
?>

<div id="wpbackitup-core-settings" v-cloak>

<!--    <div class="updated" v-show="updated">-->
<!--        <p>--><?php //_e( 'Settings updated successfully!', 'wp-backitup' ); ?><!--</p>-->
<!--    </div>-->


    <div id="wpbackitup-header">
        <h1><?php echo esc_html( $page_title ); ?></h1>
    </div>

    <div id="wpbackitup-settings" v-if="loading === false">
        <?php
            // Nonce
            echo '<input type="hidden" name="wpbackitup-core-ajax-nonce" id="wpbackitup-core-ajax-nonce" value="' . esc_attr( wp_create_nonce( 'wpbackitup-core-ajax-nonce' ) ) . '" />';
        ?>

        <vue-tabs active-tab-color="#f1f1f1" active-text-color="black">
            <v-tab title="<?php esc_attr_e( 'General', 'wp-backitup' ); ?>" icon="dashicons-admin-generic">

                <div class="widget">
                    <h3 class="promo"><span class="dashicons dashicons-email-alt"></span> <?php esc_html_e('Email Notifications', 'wp-backitup')  ?></h3>
                    <p><b><?php esc_html_e('Please enter your email address if you would like to receive backup email notifications.', 'wp-backitup') ?></b></p>
                    <p><?php esc_html_e('Backup email notifications will be sent for every backup and will contain status information related to the backup.', 'wp-backitup'); ?></p>
                    <p><input-tags :on-change="handleEmailInput" :tags="emailsArray" validate="email"></input-tags></p>
                    <div class="submit">
                        <button class="button-primary" v-on:click="setSettings()"><?php esc_html_e("Save", 'wp-backitup') ?></button>
                    </div>

                    <p class="error" v-if="errorMessages['notification_email'] !== '' ">{{ errorMessages['notification_email'] }}</p>
                </div>


                <div class="widget">
                    <h3 class="promo"><span class="dashicons dashicons-trash"></span> <?php esc_html_e('Backup Retention', 'wp-backitup') ?></h3>
                    <p><b><?php esc_html_e('Enter the number of backup archives that you would like to remain on the server.', 'wp-backitup') ?></b></p>
                    <p><?php esc_html_e('Many hosts limit the amount of space that you can take up on their servers. This option tells WPBackItUp the maximum number of backup archives that should remain on your hosts server.  Don\'t worry, we will always remove the oldest backup archives first.', 'wp-backitup') ?></p>
                    <p><input type="text" v-model="backup_retained_number" size="4"></p>
                    <div class="submit">
                        <button class="button-primary" v-on:click="setSettings()"><?php esc_html_e("Save", 'wp-backitup') ?></button>
                    </div>

                    <p class="error" v-if="errorMessages['backup_retained_number'] !== '' ">{{ errorMessages['backup_retained_number'] }}</p>
                </div>

                <div class="widget">
                    <h3 class="promo"><span class="dashicons dashicons-media-text"></span> <?php esc_html_e('Logging?', 'wp-backitup') ?></h3>
                    <p><b><?php esc_html_e('Turn on WPBackItUp logging.', 'wp-backitup'); ?></b></p>
                    <p><?php esc_html_e('This option should only be turned on by advanced users or when troubleshooting issues with WPBackItUp support.', 'wp-backitup'); ?></p>
                    <p><input type="radio" v-model="logging" value="true" checked="logging === true"> <label><?php esc_html_e('Yes', 'wp-backitup'); ?></label></p>
                    <p><input type="radio" v-model="logging" value="false" checked="logging === false"> <label><?php esc_html_e('No', 'wp-backitup'); ?></label></p>

                    <div class="submit">
                        <button class="button-primary" v-on:click="setSettings()"><?php esc_html_e("Save", 'wp-backitup') ?></button>
                    </div>
                </div>

                <!-- Premium settings -->
                <?php do_action('wpbackitup_render_premium_settings'); ?>


                <div class="widget">
                    <h3 class="promo"><span class="dashicons dashicons-database"></span> <?php esc_html_e('Single File Database Export (db)', 'wp-backitup') ?></h3>
                    <p><input type="checkbox" v-model="single_file_db" checked="single_file_db === true">
                        <label for="wpbackitup_single_file_db"><?php esc_html_e('Check this box if you would like WPBackItUp to export your database into a single db file.', 'wp-backitup') ?></label></p>
                    <p><?php esc_html_e('When this setting is turned on WPBackItUp will attempt to create a single file that contains your entire database.  This option may not be possible with some hosting providers.  This setting will be turned off automatically if WPBackItUp is unable to complete this step for any reason.', 'wp-backitup') ?></p>

                    <div class="submit">
                        <button class="button-primary" v-on:click="setSettings()"><?php esc_html_e("Save", 'wp-backitup') ?></button>
                    </div>
                </div>

                <div class="widget dbfilters">
                    <h3 class="promo"><span class="dashicons dashicons-filter"></span> <?php esc_html_e('Filter Your Database Tables', 'wp-backitup') ?></h3>
                    <p><b><?php esc_html_e('Exclude custom database tables from the backup.', 'wp-backitup') ?></b></p>
                    <p><?php esc_html_e('If you would like to exclude a custom table from the backup then simply select it to the list on the right.  WordPress core tables may not be excluded from the backup. ', 'wp-backitup') ?></p>
                    <ui-select
                            has-search
                            :disabled="dbFilterHasSearch"
                            label=""
                            multiple
                            :placeholder="dbFilterPlaceholder"
                            type="image"
                            :options="dbFilterOptions"
                            v-model="db_filters"
                    ></ui-select>

                    <div class="submit">
                        <button class="button-primary" v-on:click="setSettings()"><?php esc_html_e("Save", 'wp-backitup') ?></button>
                    </div>

                    <p><?php esc_html_e('* These settings should only be modified by advanced users or when when working with WPBackItUp support.', 'wp-backitup') ?></p>
                </div>

                <div class="widget filters">
                    <h3 class="promo"><span class="dashicons dashicons-filter"></span> <?php esc_html_e('Filter Your Folders', 'wp-backitup') ?></h3>
                    <p><b><?php esc_html_e('Enter a comma separated list of folders that should be excluded from your backups.', 'wp-backitup') ?></b></p>
                    <p><?php esc_html_e('It is important to note that when a folder name is present in this list any occurrence of that folder, and all its contents, will be excluded from the backup.', 'wp-backitup') ?></p>
                    <p>
                        <label> <?php esc_html_e('Plugin Folders Filter', 'wp-backitup') ?></label>
                        <input-tags :on-change="handleTagsInput" :tags="backup_plugins_filter"></input-tags>
                    </p>

                    <p>
                        <label> <?php esc_html_e('Theme Folders Filter', 'wp-backitup') ?></label>
                        <input-tags :on-change="handleTagsInput" :tags="backup_themes_filter"></input-tags>
                    </p>

                    <p>
                        <label> <?php esc_html_e('Upload Folders Filter', 'wp-backitup') ?></label>
                        <input-tags :on-change="handleTagsInput" :tags="backup_uploads_filter"></input-tags>
                    </p>
                    <p>
                        <label> <?php esc_html_e('Other Folders Filter', 'wp-backitup') ?></label>
                        <input-tags :on-change="handleTagsInput" :tags="backup_others_filter"></input-tags>
                    </p>
                    <div class="submit">
                        <button class="button-primary" v-on:click="setSettings()"><?php esc_html_e("Save", 'wp-backitup') ?></button>
                    </div>
                    <p><?php esc_html_e('* These settings should only be modified by advanced users or when when working with WPBackItUp support.', 'wp-backitup') ?></p>
                </div>

	            <div class="widget">
		            <h3 class="promo"><span class="dashicons dashicons-chart-line"></span> <?php esc_html_e('Help us make WPBackItUp better!', 'wp-backitup')  ?></h3>
		            <p><input type="checkbox" v-model="allow_usage_tracking" checked="allow_usage_tracking === true">
			            <label for="wpbackitup_allow_tracking"><?php esc_html_e('Allow WPBackItUp to anonymously track how this plugin is used so we can make it better.', 'wp-backitup') ?></label></p>
		            <p><?php esc_html_e('Only data needed to help support and improve this plugin will ever be collected. No sensitive data is tracked and we\'ll never share this data with anyone.', 'wp-backitup') ?></p>
		            <div class="submit">
			            <button class="button-primary" v-on:click="setSettings()"><?php esc_html_e("Save", 'wp-backitup') ?></button>
		            </div>

		            <p class="error" v-if="errorMessages['notification_email'] !== '' ">{{ errorMessages['notification_email'] }}</p>
	            </div>

            </v-tab>


            <v-tab title="<?php esc_attr_e( 'Advanced', 'wp-backitup' ); ?>" icon="dashicons-admin-settings">

                <div class="widget">
                    <h3 class="promo"><span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e('Batch Size', 'wp-backitup') ?></h3>
                    <p><b><?php esc_html_e('Enter the batch size for each of your content items.', 'wp-backitup') ?></b></p>
                    <p><?php esc_html_e('These settings tell WPBackItUp how many items that should be added to the backup at a time.  If you experience timeouts while running a backup then these settings can be used to help reduce the amount of time it takes WPBackItUp to complete each backup task .', 'wp-backitup') ?></p>
                    <p>
                        <input v-model="backup_dbtables_batch_size" type="text" size="3" />
                        <label> <?php esc_html_e('DB Tables Batch Size', 'wp-backitup') ?></label>
                    </p>
                    <p class="error" v-if="errorMessages['backup_dbtables_batch_size'] !== '' ">{{ errorMessages['backup_dbtables_batch_size'] }}</p>

                    <p>
                        <input v-model="backup_sql_merge_batch_size" type="text" size="3" />
                        <label> <?php esc_html_e('SQL Merge Batch Size', 'wp-backitup') ?></label>
                    </p>
                    <p class="error" v-if="errorMessages['backup_sql_merge_batch_size'] !== '' ">{{ errorMessages['backup_sql_merge_batch_size'] }}</p>

                    <p>
                        <input v-model="backup_sql_batch_size" type="text" size="3" />
                        <label> <?php esc_html_e('SQL Batch Size', 'wp-backitup') ?></label>
                    </p>
                    <p class="error" v-if="errorMessages['backup_sql_batch_size'] !== '' ">{{ errorMessages['backup_sql_batch_size'] }}</p>

                    <p>
                        <input v-model="backup_plugins_batch_size" type="text" size="3" />
                        <label> <?php esc_html_e('Plugins Batch Size', 'wp-backitup') ?></label>
                    </p>
                    <p class="error" v-if="errorMessages['backup_plugins_batch_size'] !== '' ">{{ errorMessages['backup_plugins_batch_size'] }}</p>

                    <p>
                        <input v-model="backup_themes_batch_size" type="text" size="3" />
                        <label> <?php esc_html_e('Themes Batch Size', 'wp-backitup') ?></label>
                    </p>
                    <p class="error" v-if="errorMessages['backup_themes_batch_size'] !== '' ">{{ errorMessages['backup_themes_batch_size'] }}</p>

                    <p>
                        <input v-model="backup_uploads_batch_size" type="text" size="3" />
                        <label> <?php esc_html_e('Uploads Batch Size', 'wp-backitup') ?></label>
                    </p>
                    <p class="error" v-if="errorMessages['backup_uploads_batch_size'] !== '' ">{{ errorMessages['backup_uploads_batch_size'] }}</p>

                    <p>
                        <input v-model="backup_others_batch_size" type="text" size="3" />
                        <label> <?php esc_html_e('Others Batch Size', 'wp-backitup') ?></label>
                    </p>
                    <p class="error" v-if="errorMessages['backup_others_batch_size'] !== '' ">{{ errorMessages['backup_others_batch_size'] }}</p>

                    <div class="submit">
                        <button class="button-primary" v-on:click="setSettings()"><?php esc_html_e("Save", 'wp-backitup') ?></button>
                    </div>

                    <p><?php esc_html_e('* These settings should only be modified by advanced users or when when working with WPBackItUp support.', 'wp-backitup') ?></p>

                    </p>
                </div>


                <div class="widget">
                    <h3 class="promo"><span class="dashicons dashicons-media-archive"></span> <?php esc_html_e('Maximum Zip File Size', 'wp-backitup') ?></h3>
                    <div class="wpbiu-select-box">
                        <p><b><label for="wpbackitup-max-zip-size"><?php esc_html_e('Select your maximum zip file size.', 'wp-backitup') ?></label></b></p>
                        <p><?php esc_html_e('Some hosting providers do not allow large zip files so if you are encountering backup errors then reducing this setting may help. Please note that this setting will impact performance so we recommend it is set as high as possible.', 'wp-backitup') ?></p>
                        <select class="form-control" v-model="backup_zip_max_size">
                            <option value="104857600"><?php esc_html_e('100MB', 'wp-backitup') ?></option>
                            <option value="209715200"><?php esc_html_e('200MB', 'wp-backitup') ?></option>
                            <option value="314572800"><?php esc_html_e('300MB', 'wp-backitup') ?></option>
                            <option value="419430400"><?php esc_html_e('400MB', 'wp-backitup') ?></option>
                            <option value="524288000"><?php esc_html_e('500MB', 'wp-backitup') ?></option>
                            <option value="1073741824"><?php esc_html_e('1GB', 'wp-backitup') ?></option>
                            <option value="1610612736"><?php esc_html_e('1.5GB', 'wp-backitup') ?></option>
                            <option value="2147483648"><?php esc_html_e('2GB', 'wp-backitup') ?></option>
                        </select>
                    </div>

                    <div class="submit">
                        <button class="button-primary" v-on:click="setSettings()"><?php esc_html_e("Save", 'wp-backitup') ?></button>
                    </div>
                </div>

                <div class="widget">
                    <h3 class="promo"><span class="dashicons dashicons-clock"></span> <?php esc_html_e('Task Timeout', 'wp-backitup') ?></h3>
                    <div class="wpbiu-select-box">
                        <p><b><label for="wpbackitup-max-zip-size"><?php esc_html_e('Select how long WPBackItUp should wait for tasks to complete.', 'wp-backitup') ?></label></b></p>
                        <p><?php esc_html_e('On some hosts background tasks are allowed to run for a very limited amount of time before they timeout. This setting will tell WPBackItUp how long to wait for each background task to complete.  This setting should only be used when working with WPBackItUp support.', 'wp-backitup') ?></p>
                        <select class="form-control" v-model="backup_max_timeout">
                            <option value="60"><?php esc_html_e('1 Minute', 'wp-backitup') ?></option>
                            <option value="120"><?php esc_html_e('2 Minute', 'wp-backitup') ?></option>
                            <option value="180"><?php esc_html_e('3 Minute', 'wp-backitup') ?></option>
                            <option value="240"><?php esc_html_e('4 Minute', 'wp-backitup') ?></option>
                            <option value="300"><?php esc_html_e('5 Minute', 'wp-backitup') ?></option>
                        </select>
                    </div>

                    <div class="submit">
                        <button class="button-primary" v-on:click="setSettings()"><?php esc_html_e("Save", 'wp-backitup') ?></button>
                    </div>
                </div>


                <div class="widget">
                    <h3 class="promo">
                        <span class="dashicons dashicons-cloud"></span>
                        <?php esc_html_e(' WPBackItUp Safe Sync', 'wp-backitup') ?>
                    </h3>
                    <p>
                        <input type="checkbox" v-model="safe_sync" checked="safe_sync === true">
                        <label for="wpbackitup_safe_sync"><?php printf( wp_kses_post( __('Check this box if you would like to turn <strong>on</strong> WPBackItUp Safe.', 'wp-backitup') ) ); ?></label>
                    </p>
                    <div class="submit">
                        <button class="button-primary" v-on:click="setSettings()"><?php esc_html_e("Save", 'wp-backitup') ?></button>
                    </div>
                </div>

	            <div class="widget">
		            <h3 class="promo">
			            <span class="dashicons dashicons-warning"></span>
                       <?php esc_html_e(' WPBackItUp Beta Updates', 'wp-backitup') ?>
		            </h3>
		            <p>
			            <input type="checkbox" v-model="beta_updates" checked="beta_updates === true">
			            <label for="wpbackitup_beta_updates"><?php printf( wp_kses_post( __('Check this box if you would like to receive <strong>pre-release</strong> updates of WPBackItUp products.', 'wp-backitup') ) ); ?></label>
		            </p>
		            <div class="submit">
			            <button class="button-primary" v-on:click="setSettings()"><?php esc_html_e("Save", 'wp-backitup') ?></button>
		            </div>
	            </div>

                <div class="widget">
                    <h3 class="promo"><span class="dashicons dashicons-trash"></span> <?php esc_html_e('Remove Data on Uninstall?', 'wp-backitup') ?></h3>
                    <p>
                        <input type="checkbox" v-model="delete_all" checked="delete_all === true">
                        <label for="wpbackitup_delete_all"><?php esc_html_e('Check this box if you would like WPBackItUp to completely remove all of its data when the plugin is deleted.', 'wp-backitup') ?></label>
                    </p>
                    <div class="submit">
                        <button class="button-primary" v-on:click="setSettings()"><?php esc_html_e("Save", 'wp-backitup') ?></button>
                    </div>
                </div>

            </v-tab>

            <v-tab title="<?php esc_attr_e( 'Event Logging', 'wp-backitup' ); ?>" icon="dashicons-chart-bar">

                <div class="widget">
                    <h3 class="promo">
                        <span class="dashicons dashicons-chart-bar"></span>
                        <?php esc_html_e('Backup Recommendations', 'wp-backitup') ?>
                    </h3>
                    <p><b><?php esc_html_e('Enable smart backup recommendations based on site activity.', 'wp-backitup') ?></b></p>
                    <p><?php esc_html_e('When enabled, WPBackItUp monitors your site for important changes (plugin updates, content changes, security events) and recommends when you should create a backup.', 'wp-backitup'); ?></p>
                    <p>
                        <input type="checkbox" v-model="event_logging_enabled" :true-value="true" :false-value="false">
                        <label><?php esc_html_e('Enable Event Logging and Backup Recommendations', 'wp-backitup') ?></label>
                    </p>
                    <div class="submit">
                        <button class="button-primary" v-on:click="setSettings()"><?php esc_html_e("Save", 'wp-backitup') ?></button>
                    </div>
                </div>

                <div class="widget" v-if="event_logging_enabled">
                    <h3 class="promo">
                        <span class="dashicons dashicons-info"></span>
                        <?php esc_html_e('Event Statistics', 'wp-backitup') ?>
                    </h3>
                    <p><b><?php esc_html_e('Events captured in the last 7 days:', 'wp-backitup') ?></b></p>
                    <table class="widefat" style="max-width: 400px;">
                        <tr>
                            <td><?php esc_html_e('Total Events', 'wp-backitup') ?></td>
                            <td><strong>{{ event_stats.total_events }}</strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Updates Applied', 'wp-backitup') ?></td>
                            <td><strong>{{ event_stats.updates_applied }}</strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Content Changes', 'wp-backitup') ?></td>
                            <td><strong>{{ event_stats.content_changes }}</strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Security Events', 'wp-backitup') ?></td>
                            <td><strong>{{ event_stats.security_events }}</strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Settings Changes', 'wp-backitup') ?></td>
                            <td><strong>{{ event_stats.settings_changes }}</strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Pending Updates', 'wp-backitup') ?></td>
                            <td><strong>{{ event_stats.pending_updates }}</strong></td>
                        </tr>
                    </table>
                    <p class="description"><?php esc_html_e('Events older than 7 days are automatically deleted.', 'wp-backitup') ?></p>
                </div>

            </v-tab>
        </vue-tabs>
    </div>
</div>



<script type="text/x-template" id="input-tags-template">
    <div @click="focusNewTag()" v-bind:class="{'read-only': readOnly}" class="vue-input-tag-wrapper">
    <span v-for="(tag, index) in tags" v-bind:key="index" class="input-tag">
      <span>{{ tag }}</span>
      <a v-if="!readOnly" @click.prevent.stop="remove(index)" class="remove"></a>
    </span>
        <input v-if="!readOnly" v-bind:placeholder="placeholder" type="text" v-model="newTag" v-on:keydown.delete.stop="removeLastTag()" v-on:keydown.enter.188.prevent.stop="addNew(newTag)" v-on:keydown.space.prevent.stop="addNew(newTag)" class="new-tag"/>
    </div>
</script>