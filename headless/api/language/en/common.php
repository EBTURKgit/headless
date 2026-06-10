<?php

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	'HEADLESS_API_TITLE'       => 'Headless API',
	'HEADLESS_API_DESC'        => 'phpBB 4 Headless REST API layer',

	// Error codes
	'API_ERROR_UNAUTHORIZED'        => 'You must be logged in to perform this action.',
	'API_ERROR_PERMISSION_DENIED'   => 'You do not have permission to perform this action.',
	'API_ERROR_NOT_FOUND'           => 'The requested resource was not found.',
	'API_ERROR_VALIDATION'          => 'The submitted data is invalid.',
	'API_ERROR_RATE_LIMIT'          => 'Too many requests. Please wait before trying again.',
	'API_ERROR_INTERNAL'            => 'An unexpected error occurred.',
	'API_ERROR_INVALID_TOKEN'       => 'Invalid or expired token.',
	'API_ERROR_TOKEN_REQUIRED'      => 'API token is required. Send it via Authorization: Bearer <token> header.',

	// Auth
	'API_LOGIN_SUCCESS'             => 'Login successful.',
	'API_LOGIN_FAILED'              => 'Invalid username or password.',
	'API_LOGOUT_SUCCESS'            => 'Successfully logged out.',
	'API_TOKEN_REFRESHED'           => 'Token refreshed successfully.',

	// Register
	'API_REGISTER_SUCCESS'          => 'Registration successful. Please check your email for verification.',
	'API_REGISTER_SUCCESS_NOVERIFY' => 'Registration successful. You can now log in.',
	'API_REGISTER_FAILED'           => 'Registration failed.',
	'API_EMAIL_VERIFIED'            => 'Your email has been verified successfully.',
	'API_VERIFICATION_RESENT'       => 'Verification email has been resent.',

	// Password
	'API_PASSWORD_RESET_SENT'       => 'A password reset link has been sent to your email.',
	'API_PASSWORD_RESET_SUCCESS'    => 'Your password has been changed successfully.',

	// Messages
	'API_MESSAGE_SENT'              => 'Message sent successfully.',
	'API_MESSAGE_DELETED'           => 'Message deleted.',
	'API_MESSAGE_MOVED'             => 'Message moved.',
	'API_MESSAGE_READ'              => 'Message marked as read.',
	'API_MESSAGE_UNREAD'            => 'Message marked as unread.',

	// Bookmarks
	'API_BOOKMARK_ADDED'            => 'Topic added to bookmarks.',
	'API_BOOKMARK_REMOVED'          => 'Topic removed from bookmarks.',
	'API_BOOKMARK_EXISTS'           => 'Topic is already bookmarked.',

	// Drafts
	'API_DRAFT_SAVED'              => 'Draft saved.',
	'API_DRAFT_UPDATED'            => 'Draft updated.',
	'API_DRAFT_DELETED'            => 'Draft deleted.',

	// Friends
	'API_FRIEND_ADDED'             => 'User added to your friends list.',
	'API_FRIEND_REMOVED'           => 'User removed from your friends list.',
	'API_FOE_ADDED'                => 'User added to your foes list.',
	'API_FOE_REMOVED'              => 'User removed from your foes list.',

	// Favorites
	'API_FAVORITE_ADDED'           => 'Forum added to favorites.',
	'API_FAVORITE_REMOVED'         => 'Forum removed from favorites.',

	// Reports
	'API_REPORT_CREATED'           => 'Report submitted successfully.',
	'API_REPORT_RESOLVED'          => 'Report resolved.',

	// Notifications
	'API_NOTIFICATIONS_READ'       => 'Notification marked as read.',
	'API_NOTIFICATIONS_ALL_READ'   => 'All notifications marked as read.',

	// Moderation
	'API_TOPIC_LOCKED'             => 'Topic locked.',
	'API_TOPIC_UNLOCKED'           => 'Topic unlocked.',
	'API_TOPIC_MOVED'              => 'Topic moved.',
	'API_TOPIC_PINNED'             => 'Topic pinned.',
	'API_TOPIC_UNPINNED'           => 'Topic unpinned.',
	'API_POST_APPROVED'            => 'Post approved.',
	'API_USER_BANNED'              => 'User banned.',
	'API_USER_UNBANNED'            => 'User unbanned.',

	// Attachments
	'API_ATTACHMENT_UPLOADED'      => 'File uploaded successfully.',
	'API_ATTACHMENT_DELETED'       => 'File deleted.',

	// Polls
	'API_VOTE_RECORDED'            => 'Your vote has been recorded.',
	'API_ALREADY_VOTED'            => 'You have already voted in this poll.',
	'API_VOTE_CHANGED'             => 'Your vote has been changed.',

	// Events
	'API_EVENT_CREATED'            => 'Event created.',
	'API_EVENT_UPDATED'            => 'Event updated.',
	'API_EVENT_DELETED'            => 'Event deleted.',
	'API_EVENT_ATTENDING'          => 'Your RSVP has been recorded.',

	// Self actions
	'API_SELF_FRIEND'              => 'You cannot add yourself as a friend.',
	'API_SELF_FOE'                 => 'You cannot add yourself as a foe.',
]);
