<?php
/**
 * Plugin Name: Herd Experience
 * Description: Shared security and admin defaults for Herd WordPress VIP applications.
 * Version: 1.6.0
 * Author: Marshall University
 * License: GPL-2.0-or-later
 * Text Domain: herd-experience
 *
 * @package HerdExperience
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-herd-experience-rest-api.php';
require_once __DIR__ . '/includes/class-herd-experience-authors.php';
require_once __DIR__ . '/includes/class-herd-experience-security-headers.php';
require_once __DIR__ . '/includes/class-herd-experience-environment-indicator.php';
require_once __DIR__ . '/includes/class-herd-experience-admin-bar.php';
require_once __DIR__ . '/includes/class-herd-experience-dashboard.php';
require_once __DIR__ . '/includes/class-herd-experience-admin-colors.php';
require_once __DIR__ . '/includes/class-herd-experience-admin-notices.php';
require_once __DIR__ . '/includes/class-herd-experience-comments.php';
require_once __DIR__ . '/includes/class-herd-experience-comments-settings.php';
require_once __DIR__ . '/includes/class-herd-experience-activity-log.php';
require_once __DIR__ . '/includes/class-herd-experience-activity-log-events.php';
require_once __DIR__ . '/includes/class-herd-experience-activity-log-admin.php';

Herd_Experience_Rest_Api::bootstrap();
Herd_Experience_Authors::bootstrap();
Herd_Experience_Security_Headers::bootstrap();
Herd_Experience_Environment_Indicator::bootstrap();
Herd_Experience_Admin_Bar::bootstrap();
Herd_Experience_Dashboard::bootstrap();
Herd_Experience_Admin_Colors::bootstrap();
Herd_Experience_Admin_Notices::bootstrap();
Herd_Experience_Comments::bootstrap();
Herd_Experience_Comments_Settings::bootstrap();
Herd_Experience_Activity_Log::bootstrap();
Herd_Experience_Activity_Log_Events::bootstrap();
Herd_Experience_Activity_Log_Admin::bootstrap();

if ( is_multisite() ) {
	require_once __DIR__ . '/includes/class-herd-experience-multisite.php';

	Herd_Experience_Multisite::bootstrap();
}
