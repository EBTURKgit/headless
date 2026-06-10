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
	'HEADLESS_API_DESC'        => 'phpBB 4 Headless REST API katmani',

	// Hata kodlari
	'API_ERROR_UNAUTHORIZED'        => 'Giris yapmaniz gerekiyor.',
	'API_ERROR_PERMISSION_DENIED'   => 'Bu islem icin yetkiniz yok.',
	'API_ERROR_NOT_FOUND'           => 'Kaynak bulunamadi.',
	'API_ERROR_VALIDATION'          => 'Gonderilen veriler hatali.',
	'API_ERROR_RATE_LIMIT'          => 'Cok fazla istek gonderildi. Lutfen bekleyin.',
	'API_ERROR_INTERNAL'            => 'Beklenmeyen bir hata olustu.',
	'API_ERROR_INVALID_TOKEN'       => 'Gecersiz veya suresi dolmus token.',
	'API_ERROR_TOKEN_REQUIRED'      => 'API token gerekli. Authorization: Bearer <token> header\'i ile gonderin.',

	// Auth
	'API_LOGIN_SUCCESS'             => 'Giris basarili.',
	'API_LOGIN_FAILED'              => 'Kullanici adi veya sifre hatali.',
	'API_LOGOUT_SUCCESS'            => 'Basariyla cikis yapildi.',
	'API_TOKEN_REFRESHED'           => 'Token basariyla yenilendi.',

	// Register
	'API_REGISTER_SUCCESS'          => 'Kayit basarili. E-posta adresinize dogrulama linki gonderildi.',
	'API_REGISTER_SUCCESS_NOVERIFY' => 'Kayit basarili. Artik giris yapabilirsiniz.',
	'API_REGISTER_FAILED'           => 'Kayit islemi basarisiz oldu.',
	'API_EMAIL_VERIFIED'            => 'E-posta adresiniz basariyla dogrulandi.',
	'API_VERIFICATION_RESENT'       => 'Dogrulama e-postasi yeniden gonderildi.',

	// Password
	'API_PASSWORD_RESET_SENT'       => 'E-posta adresinize sifre sifirlama linki gonderildi.',
	'API_PASSWORD_RESET_SUCCESS'    => 'Sifreniz basariyla degistirildi.',

	// Messages
	'API_MESSAGE_SENT'              => 'Mesaj basariyla gonderildi.',
	'API_MESSAGE_DELETED'           => 'Mesaj silindi.',
	'API_MESSAGE_MOVED'             => 'Mesaj tasindi.',
	'API_MESSAGE_READ'              => 'Mesaj okundu olarak isaretlendi.',
	'API_MESSAGE_UNREAD'            => 'Mesaj okunmadi olarak isaretlendi.',

	// Bookmarks
	'API_BOOKMARK_ADDED'            => 'Konu yer imlerine eklendi.',
	'API_BOOKMARK_REMOVED'          => 'Konu yer imlerinden kaldirildi.',
	'API_BOOKMARK_EXISTS'           => 'Konu zaten yer imlerinde.',

	// Drafts
	'API_DRAFT_SAVED'              => 'Taslak kaydedildi.',
	'API_DRAFT_UPDATED'            => 'Taslak guncellendi.',
	'API_DRAFT_DELETED'            => 'Taslak silindi.',

	// Friends
	'API_FRIEND_ADDED'             => 'Kullanici arkadas listenize eklendi.',
	'API_FRIEND_REMOVED'           => 'Kullanici arkadas listenizden kaldirildi.',
	'API_FOE_ADDED'                => 'Kullanici dusman listenize eklendi.',
	'API_FOE_REMOVED'              => 'Kullanici dusman listenizden kaldirildi.',

	// Favorites
	'API_FAVORITE_ADDED'           => 'Forum favorilere eklendi.',
	'API_FAVORITE_REMOVED'         => 'Forum favorilerden kaldirildi.',

	// Reports
	'API_REPORT_CREATED'           => 'Rapor basariyla gonderildi.',
	'API_REPORT_RESOLVED'          => 'Rapor cozumlendi.',

	// Notifications
	'API_NOTIFICATIONS_READ'       => 'Bildirim okundu olarak isaretlendi.',
	'API_NOTIFICATIONS_ALL_READ'   => 'Tum bildirimler okundu olarak isaretlendi.',

	// Moderation
	'API_TOPIC_LOCKED'             => 'Konu kilitlendi.',
	'API_TOPIC_UNLOCKED'           => 'Konu kilidi acildi.',
	'API_TOPIC_MOVED'              => 'Konu tasindi.',
	'API_TOPIC_PINNED'             => 'Konu sabitlendi.',
	'API_TOPIC_UNPINNED'           => 'Konu sabiti kaldirildi.',
	'API_POST_APPROVED'            => 'Mesaj onaylandi.',
	'API_USER_BANNED'              => 'Kullanici yasaklandi.',
	'API_USER_UNBANNED'            => 'Kullanici yasagi kaldirildi.',

	// Attachments
	'API_ATTACHMENT_UPLOADED'      => 'Dosya basariyla yuklendi.',
	'API_ATTACHMENT_DELETED'       => 'Dosya silindi.',

	// Polls
	'API_VOTE_RECORDED'            => 'Oyunuz kaydedildi.',
	'API_ALREADY_VOTED'            => 'Bu ankete daha once oy verdiniz.',
	'API_VOTE_CHANGED'             => 'Oyunuz degistirildi.',

	// Events
	'API_EVENT_CREATED'            => 'Etkinlik olusturuldu.',
	'API_EVENT_UPDATED'            => 'Etkinlik guncellendi.',
	'API_EVENT_DELETED'            => 'Etkinlik silindi.',
	'API_EVENT_ATTENDING'          => 'Etkinlige katiliminiz kaydedildi.',

	// Self actions
	'API_SELF_FRIEND'              => 'Kendinizi arkadas olarak ekleyemezsiniz.',
	'API_SELF_FOE'                 => 'Kendinizi dusman olarak ekleyemezsiniz.',
]);
