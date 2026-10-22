<?php
/**
 * Plugin Name: Herd Experience
 * Description: Shared security and admin defaults for Herd WordPress VIP applications.
 * Version: 1.0.0
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

Herd_Experience_Rest_Api::bootstrap();
Herd_Experience_Authors::bootstrap();
Herd_Experience_Security_Headers::bootstrap();
Herd_Experience_Environment_Indicator::bootstrap();
