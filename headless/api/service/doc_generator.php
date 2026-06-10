<?php
namespace headless\api\service;

use Symfony\Component\Routing\RouterInterface;

class doc_generator
{
	protected $router;
	protected $response_builder;
	protected $logger;

	public function __construct(RouterInterface $router, $response_builder, $logger)
	{
		$this->router = $router;
		$this->response_builder = $response_builder;
		$this->logger = $logger;
	}

	public function generate(): array
	{
		$this->logger->info('DOC', 'Generating OpenAPI documentation');

		$routes = $this->router->getRouteCollection();

		$openapi = [
			'openapi' => '3.0.0',
			'info' => [
				'title' => 'phpBB 4 Headless API',
				'version' => '1.0.0',
				'description' => 'RESTful API layer for phpBB 4. Provides all forum functionality via JSON for modern frontend applications.',
				'contact' => [
					'name' => 'EBTURK',
					'url' => 'https://ebturk.com',
				],
			],
			'servers' => [
				['url' => '/api/v1', 'description' => 'API v1'],
			],
			'paths' => [],
			'components' => [
				'securitySchemes' => [
					'BearerAuth' => [
						'type' => 'http',
						'scheme' => 'bearer',
						'bearerFormat' => 'JWT',
					],
				],
				'schemas' => [
					'Error' => [
						'type' => 'object',
						'properties' => [
							'success' => ['type' => 'boolean', 'example' => false],
							'error' => [
								'type' => 'object',
								'properties' => [
									'code' => ['type' => 'string'],
									'message' => ['type' => 'string'],
									'details' => ['type' => 'object'],
								],
							],
						],
					],
					'Success' => [
						'type' => 'object',
						'properties' => [
							'success' => ['type' => 'boolean', 'example' => true],
							'data' => ['type' => 'object'],
							'meta' => [
								'type' => 'object',
								'properties' => [
									'page' => ['type' => 'integer'],
									'per_page' => ['type' => 'integer'],
									'total' => ['type' => 'integer'],
									'last_page' => ['type' => 'integer'],
									'has_more' => ['type' => 'boolean'],
								],
							],
						],
					],
				],
			],
			'tags' => [
				['name' => 'Auth', 'description' => 'Authentication operations'],
				['name' => 'Register', 'description' => 'User registration'],
				['name' => 'Forums', 'description' => 'Forum listing and viewing'],
				['name' => 'Topics', 'description' => 'Topic management'],
				['name' => 'Posts', 'description' => 'Post management'],
				['name' => 'Users', 'description' => 'User profiles'],
				['name' => 'Messages', 'description' => 'Private messages'],
				['name' => 'Bookmarks', 'description' => 'Bookmarks'],
				['name' => 'Drafts', 'description' => 'Drafts'],
				['name' => 'Polls', 'description' => 'Polls'],
				['name' => 'Friends', 'description' => 'Friend/Foe lists'],
				['name' => 'Groups', 'description' => 'User groups'],
				['name' => 'Search', 'description' => 'Search'],
				['name' => 'Notifications', 'description' => 'Notifications'],
				['name' => 'Moderation', 'description' => 'Moderation operations'],
				['name' => 'Attachments', 'description' => 'File attachments'],
				['name' => 'Meta', 'description' => 'Site information'],
			],
		];

		$route_count = 0;
		foreach ($routes as $name => $route)
		{
			$path = $route->getPath();
			$methods = $route->getMethods();

			if (strpos($path, '/api/v1/') === 0)
			{
				$clean_path = str_replace('/api/v1', '', $path);

				$route_params = [];
				if (preg_match_all('/\{(\w+)\}/', $clean_path, $matches))
				{
					foreach ($matches[1] as $param)
					{
						$requirements = $route->getRequirements();
						$route_params[] = [
							'name' => $param,
							'in' => 'path',
							'required' => true,
							'schema' => [
								'type' => 'integer',
								'pattern' => $requirements[$param] ?? null,
							],
						];
						$clean_path = preg_replace('/\{' . $param . '\}/', '{' . $param . '}', $clean_path);
					}
				}

				foreach ($methods as $method)
				{
					if (!isset($openapi['paths'][$clean_path]))
					{
						$openapi['paths'][$clean_path] = [];
					}

					$method_lower = strtolower($method);
					$entry = [
						'summary' => $this->getSummaryFromRoute($name),
						'operationId' => $name,
						'parameters' => $route_params,
						'responses' => [
							'200' => ['description' => 'Success'],
							'400' => ['description' => 'Bad request', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
							'401' => ['description' => 'Unauthorized', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
							'403' => ['description' => 'Forbidden', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
							'404' => ['description' => 'Not found', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
							'422' => ['description' => 'Validation error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
						],
					];

					if (in_array($method, ['POST', 'PUT', 'PATCH']) && $method_lower !== 'delete')
					{
						$request_schema = $this->getRequestSchema($name);
						if ($request_schema)
						{
							$entry['requestBody'] = [
								'required' => true,
								'content' => [
									'application/json' => [
										'schema' => $request_schema,
									],
								],
							];
						}
					}

					$openapi['paths'][$clean_path][$method_lower] = $entry;

					$tag = $this->getTagFromRoute($name);
					if ($tag)
					{
						$openapi['paths'][$clean_path][$method_lower]['tags'] = [$tag];
					}

					$route_count++;
				}
			}
		}

		$this->logger->info('DOC', 'Generated documentation for ' . $route_count . ' routes');
		return $openapi;
	}

	private function getSummaryFromRoute(string $route_name): string
	{
		$parts = explode('_', $route_name);
		$action = array_pop($parts);
		$resource = array_pop($parts);

		$action_map = [
			'index' => 'List',
			'show' => 'Show',
			'create' => 'Create',
			'update' => 'Update',
			'delete' => 'Delete',
			'login' => 'Login',
			'logout' => 'Logout',
			'me' => 'My profile',
			'refresh' => 'Refresh token',
			'forgotPassword' => 'Forgot password',
			'resetPassword' => 'Reset password',
			'register' => 'Register',
			'verifyEmail' => 'Verify email',
			'resendVerification' => 'Resend verification email',
			'markRead' => 'Mark as read',
			'markUnread' => 'Mark as unread',
			'markAllRead' => 'Mark all as read',
			'reply' => 'Reply',
			'move' => 'Move',
			'folders' => 'List folders',
			'vote' => 'Vote',
			'changeVote' => 'Change vote',
			'results' => 'Show results',
			'add' => 'Add',
			'remove' => 'Remove',
			'addFoe' => 'Add foe',
			'removeFoe' => 'Remove foe',
			'members' => 'List members',
			'subscribe' => 'Subscribe/Unsubscribe',
			'report' => 'Report',
			'react' => 'React',
			'resolve' => 'Resolve',
			'lockTopic' => 'Lock/Unlock topic',
			'moveTopic' => 'Move topic',
			'pinTopic' => 'Pin/Unpin topic',
			'approvePost' => 'Approve post',
			'banUser' => 'Ban/Unban user',
			'upload' => 'Upload',
			'bbcodePreview' => 'BBCode preview',
			'onlineUsers' => 'Online users',
			'languages' => 'List languages',
			'setLanguage' => 'Set language',
			'styles' => 'List styles',
			'setStyle' => 'Set style',
			'save' => 'Save',
			'saved' => 'Saved searches',
			'savedSearch' => 'Saved search',
			'deleteSavedSearch' => 'Delete saved search',
			'topics' => 'List topics',
			'topicTags' => 'Topic tags',
			'addTag' => 'Add tag',
			'removeTag' => 'Remove tag',
			'attend' => 'Attend',
			'index' => 'List',
			'stats' => 'Statistics',
		];

		return $action_map[$action] ?? $action;
	}

	private function getTagFromRoute(string $route_name): ?string
	{
		$parts = explode('_', $route_name);

		$tag_map = [
			'auth' => 'Auth',
			'register' => 'Register',
			'forum' => 'Forums',
			'topic' => 'Topics',
			'post' => 'Posts',
			'user' => 'Users',
			'message' => 'Messages',
			'bookmark' => 'Bookmarks',
			'draft' => 'Drafts',
			'poll' => 'Polls',
			'friend' => 'Friends',
			'group' => 'Groups',
			'search' => 'Search',
			'notification' => 'Notifications',
			'mod' => 'Moderation',
			'attachment' => 'Attachments',
			'meta' => 'Meta',
		];

		foreach ($tag_map as $key => $tag)
		{
			if (in_array($key, $parts))
			{
				return $tag;
			}
		}

		return null;
	}

	private function getRequestSchema(string $route_name): ?array
	{
		$schemas = [
			'auth_login' => [
				'type' => 'object',
				'required' => ['username', 'password'],
				'properties' => [
					'username' => ['type' => 'string', 'description' => 'Username'],
					'password' => ['type' => 'string', 'format' => 'password', 'description' => 'Password'],
				],
			],
			'auth_forgot_password' => [
				'type' => 'object',
				'required' => ['email'],
				'properties' => [
					'email' => ['type' => 'string', 'format' => 'email', 'description' => 'Email address'],
				],
			],
			'auth_reset_password' => [
				'type' => 'object',
				'required' => ['token', 'password'],
				'properties' => [
					'token' => ['type' => 'string', 'description' => 'Password reset token'],
					'password' => ['type' => 'string', 'format' => 'password', 'description' => 'New password'],
				],
			],
			'register' => [
				'type' => 'object',
				'required' => ['username', 'password', 'email'],
				'properties' => [
					'username' => ['type' => 'string', 'description' => 'Username'],
					'password' => ['type' => 'string', 'format' => 'password', 'description' => 'Password'],
					'email' => ['type' => 'string', 'format' => 'email', 'description' => 'Email address'],
				],
			],
			'topic_create' => [
				'type' => 'object',
				'required' => ['title', 'text'],
				'properties' => [
					'title' => ['type' => 'string', 'description' => 'Topic title'],
					'text' => ['type' => 'string', 'description' => 'Message content (BBCode)'],
				],
			],
			'topic_update' => [
				'type' => 'object',
				'properties' => [
					'title' => ['type' => 'string', 'description' => 'Topic title'],
				],
			],
			'post_create' => [
				'type' => 'object',
				'required' => ['text'],
				'properties' => [
					'text' => ['type' => 'string', 'description' => 'Message content (BBCode)'],
				],
			],
			'post_update' => [
				'type' => 'object',
				'required' => ['text'],
				'properties' => [
					'text' => ['type' => 'string', 'description' => 'Message content (BBCode)'],
				],
			],
			'post_report' => [
				'type' => 'object',
				'required' => ['reason'],
				'properties' => [
					'reason' => ['type' => 'string', 'description' => 'Report reason'],
				],
			],
			'post_react' => [
				'type' => 'object',
				'required' => ['reaction'],
				'properties' => [
					'reaction' => ['type' => 'string', 'description' => 'Reaction type (like, love, laugh, sad, angry)'],
				],
			],
			'message_create' => [
				'type' => 'object',
				'required' => ['to', 'text'],
				'properties' => [
					'to' => ['type' => 'integer', 'description' => 'Recipient user ID'],
					'text' => ['type' => 'string', 'description' => 'Message content (BBCode)'],
				],
			],
			'message_reply' => [
				'type' => 'object',
				'required' => ['text'],
				'properties' => [
					'text' => ['type' => 'string', 'description' => 'Message content (BBCode)'],
				],
			],
			'event_create' => [
				'type' => 'object',
				'required' => ['title', 'event_start', 'event_end'],
				'properties' => [
					'title' => ['type' => 'string', 'description' => 'Event title'],
					'description' => ['type' => 'string', 'description' => 'Event description'],
					'event_start' => ['type' => 'integer', 'description' => 'Start time (unix timestamp)'],
					'event_end' => ['type' => 'integer', 'description' => 'End time (unix timestamp)'],
					'location' => ['type' => 'string', 'description' => 'Location'],
					'max_attendees' => ['type' => 'integer', 'description' => 'Maximum attendees (0 = unlimited)'],
				],
			],
			'event_attend' => [
				'type' => 'object',
				'required' => ['status'],
				'properties' => [
					'status' => ['type' => 'string', 'enum' => ['attending', 'maybe', 'declined'], 'description' => 'Attendance status'],
				],
			],
		];

		foreach ($schemas as $key => $schema)
		{
			if (str_contains($route_name, $key))
			{
				return $schema;
			}
		}

		return null;
	}
}
