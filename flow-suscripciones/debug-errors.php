<?php
/**
 * Quick error diagnostic for Flow plugin
 */

if (!defined('ABSPATH')) exit;

// Debug information available - admin notices disabled to reduce spam
// Debug data is logged to error log instead of showing notices

// Log initialization attempt
error_log('Flow Debug: Debug script loaded at ' . current_time('Y-m-d H:i:s'));