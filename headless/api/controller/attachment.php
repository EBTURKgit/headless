<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class attachment
{
	protected $db;
	protected $auth;
	protected $user;
	protected $config;
	protected $response;
	protected $guard;
	protected $permission;
	protected $logger;

	public function __construct($db, $auth, $user, $config, $response, $guard, $permission, $logger)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->config = $config;
		$this->response = $response;
		$this->guard = $guard;
		$this->permission = $permission;
		$this->logger = $logger;
	}

	/**
	 * POST /api/v1/attachments/upload
	 *
	 * Upload a file attachment.
	 *
	 * Expects multipart/form-data with a "file" field.
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function upload(Request $request): JsonResponse
	{
		$this->logger->request('POST', '/api/v1/attachments/upload');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$auth_result = $this->guard->check($request);
		if ($auth_result !== null)
		{
			return $auth_result;
		}

		$user_id = $this->guard->userId();

		try
		{
			$uploaded_file = $request->files->get('file');
		}
		catch (\Throwable $e)
		{
			$this->logger->error('ATTACHMENT', 'Upload failed: file access error - ' . $e->getMessage());
			return $this->response->validationError(['file' => 'Could not access the file.']);
		}

		if (!$uploaded_file)
		{
			$this->logger->warn('ATTACHMENT', 'Upload failed: no file provided');
			return $this->response->validationError(['file' => 'File is required.']);
		}

		if (!$uploaded_file->isValid())
		{
			$this->logger->warn('ATTACHMENT', 'Upload failed: invalid file');
			return $this->response->validationError(['file' => 'Invalid file.']);
		}

		$max_filesize = (int) $this->config->offsetGet('max_filesize');
		$uploaded_size = 0;
		if ($max_filesize > 0)
		{
			$uploaded_size = $this->getFileSize($uploaded_file);
			if ($uploaded_size > $max_filesize)
			{
				$this->logger->warn('ATTACHMENT', 'Upload failed: file exceeds max filesize');
				return $this->response->validationError(['file' => 'File size exceeds the maximum.']);
			}
		}

		$allowed_extensions = ['jpg', 'jpeg', 'gif', 'png', 'zip', 'rar', 'pdf', 'doc', 'docx', 'txt'];
		$extension = strtolower($uploaded_file->getClientOriginalExtension());

		if (!in_array($extension, $allowed_extensions))
		{
			$this->logger->warn('ATTACHMENT', 'Upload failed: disallowed extension ' . $extension);
			return $this->response->validationError(['file' => 'This file type is not allowed.']);
		}

		$physical_filename = md5(uniqid(mt_rand(), true)) . '.' . $extension;
		$upload_dir = (string) $this->config->offsetGet('upload_path');

		if ($upload_dir === '')
		{
			$upload_dir = 'files';
		}

		global $phpbb_root_path;

		$destination = $phpbb_root_path . $upload_dir . '/' . $physical_filename;

		try
		{
			$uploaded_file->move($phpbb_root_path . $upload_dir, $physical_filename);
		}
		catch (\Throwable $e)
		{
			$this->logger->error('ATTACHMENT', 'Upload failed: move error - ' . $e->getMessage());
			return $this->response->error('UPLOAD_FAILED', 'An error occurred while uploading the file.', 500);
		}

		$now = time();
		$sql_ary = [
			'post_msg_id'    => 0,
			'poster_id'      => $user_id,
			'is_orphan'      => 1,
			'physical_filename' => $physical_filename,
			'real_filename'  => $uploaded_file->getClientOriginalName(),
			'download_count' => 0,
			'attach_comment' => '',
			'extension'      => $extension,
			'mimetype'       => $uploaded_file->getClientMimeType(),
			'filesize'       => $uploaded_size,
			'filetime'       => $now,
			'thumbnail'      => 0,
		];

		$sql = 'INSERT INTO ' . ATTACHMENTS_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_ary);
		$this->db->sql_query($sql);
		$attach_id = (int) $this->db->sql_nextid();

		$this->logger->info('ATTACHMENT', "Uploaded file #{$attach_id} by user #{$user_id}: {$physical_filename}");

		return $this->response->success([
			'attach_id' => $attach_id,
			'filename'  => $uploaded_file->getClientOriginalName(),
			'filesize'  => $uploaded_size,
			'mimetype'  => $uploaded_file->getClientMimeType(),
		], [], 201);

	}

	private function getFileSize($file): int
	{
		$path = $file->getRealPath() ?: $file->getPathname();
		if ($path && file_exists($path)) {
			return (int) @filesize($path);
		}
		return 0;
	}

	/**
	 * DELETE /api/v1/attachments/{attach_id}
	 *
	 * Delete an attachment.
	 *
	 * @param Request $request   The request object
	 * @param int     $attach_id The attachment ID
	 * @return JsonResponse
	 */
	public function delete(Request $request, int $attach_id): JsonResponse
	{
		$this->logger->request('DELETE', '/api/v1/attachments/' . $attach_id);

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$auth_result = $this->guard->check($request);
		if ($auth_result !== null)
		{
			return $auth_result;
		}

		$user_id = $this->guard->userId();

		$sql = 'SELECT * FROM ' . ATTACHMENTS_TABLE . '
				WHERE attach_id = ' . (int) $attach_id;
		$result = $this->db->sql_query($sql);
		$attachment = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$attachment)
		{
			$this->logger->warn('ATTACHMENT', 'Attachment #' . $attach_id . ' not found for delete');
			return $this->response->notFound('File not found.');
		}

		if ((int) $attachment['poster_id'] !== $user_id && !$this->permission->isAdmin())
		{
			$this->logger->warn('ATTACHMENT', 'User #' . $user_id . ' denied delete of attachment #' . $attach_id);
			return $this->response->forbidden('You do not have permission to delete this file.');
		}

		global $phpbb_root_path;
		$upload_dir = (string) $this->config->offsetGet('upload_path');

		if ($upload_dir === '')
		{
			$upload_dir = 'files';
		}

		$filepath = $phpbb_root_path . $upload_dir . '/' . $attachment['physical_filename'];
		if (file_exists($filepath))
		{
			unlink($filepath);
		}

		$sql = 'DELETE FROM ' . ATTACHMENTS_TABLE . '
				WHERE attach_id = ' . (int) $attach_id;
		$this->db->sql_query($sql);

		$this->logger->info('ATTACHMENT', 'Deleted attachment #' . $attach_id);

		return $this->response->success(['message' => 'File deleted.']);
	}
}
