<?php
// Database settings - XAMPP defaults (root, empty password).
// Can be overridden with environment variables EXAM_DB_HOST / _NAME / _USER / _PASS.
define('DB_HOST', getenv('EXAM_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('EXAM_DB_NAME') ?: 'exam_system');
define('DB_USER', getenv('EXAM_DB_USER') ?: 'root');
define('DB_PASS', getenv('EXAM_DB_PASS') !== false ? getenv('EXAM_DB_PASS') : '');

// ---- Live webcam (WebRTC) ----------------------------------------------------------------------------------------
// The student's video travels browser-to-browser (encrypted with DTLS-SRTP); the server only relays tiny signalling messages.
// STUN lets the two browsers find each other across NAT. On a school network that blocks direct connections, add a TURN
// relay: set EXAM_TURN_URL (e.g. turn:turn.school.edu:3478), EXAM_TURN_USER and EXAM_TURN_PASS.
// With no internet access, devices on the same LAN still connect directly (set EXAM_STUN_URL to '' to disable the public STUN).
define('RTC_STUN_URL', getenv('EXAM_STUN_URL') !== false ? getenv('EXAM_STUN_URL') : 'stun:stun.l.google.com:19302');
define('RTC_TURN_URL',  getenv('EXAM_TURN_URL')  ?: '');
define('RTC_TURN_USER', getenv('EXAM_TURN_USER') ?: '');
define('RTC_TURN_PASS', getenv('EXAM_TURN_PASS') ?: '');
