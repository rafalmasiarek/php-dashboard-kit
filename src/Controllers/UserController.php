<?php

namespace rafalmasiarek\DashboardKit\Controllers;

use AuthKit\Auth;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use rafalmasiarek\DashboardKit\Flash;
use rafalmasiarek\DashboardKit\Hook\HookRegistry;
use rafalmasiarek\DashboardKit\Log\AuditLog;
use Slim\Views\Twig;

/**
 * Handles admin user management: listing, editing, role changes, suspend, unsuspend, and delete.
 *
 * Routes are registered internally by Dashboard and protected by admin RoleMiddleware.
 *
 * @package rafalmasiarek\DashboardKit\Controllers
 */
class UserController
{
    private const ALLOWED_SORTS = ['id', 'email', 'role', 'active', 'suspended_at'];
    private const PER_PAGE      = 25;

    /**
     * @param Twig                                                              $view                Twig rendering engine.
     * @param Auth                                                              $auth                AuthKit authentication service.
     * @param Flash                                                             $flash               Flash message store.
     * @param PDO                                                               $db                  Database connection.
     * @param array<string, array{label: string, type: string, max: int, required: bool}> $userFields Configured custom profile fields.
     * @param HookRegistry                                                      $hooks               Event hook registry.
     * @param AuditLog                                                          $audit               Audit logger.
     * @param bool                                                              $requireActivation   When true, the active column exists and is exposed in list/edit UI.
     * @param string                                                            $adminPanelUrlPrefix Full URL prefix for the admin panel (base_path + dashboard.prefix + dashboard.admin_prefix).
     */
    public function __construct(
        private readonly Twig         $view,
        private readonly Auth         $auth,
        private readonly Flash        $flash,
        private readonly PDO          $db,
        private readonly array        $userFields,
        private readonly HookRegistry $hooks,
        private readonly AuditLog     $audit,
        private readonly bool         $requireActivation = false,
        private readonly string       $adminPanelUrlPrefix = '',
    ) {
    }

    /**
     * Renders the user list with search, sortable columns and pagination.
     *
     * Query parameters:
     *   q    — search term (matches email)
     *   sort — column name; allowed: id, email, role, active, suspended_at
     *   dir  — asc or desc
     *   page — page number (1-based)
     *
     * @param  Request  $request
     * @param  Response $response
     * @return Response
     */
    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $q      = trim((string) ($params['q'] ?? ''));
        $page   = max(1, (int) ($params['page'] ?? 1));
        $sort   = in_array($params['sort'] ?? '', self::ALLOWED_SORTS, true) ? $params['sort'] : 'id';
        $dir    = strtoupper($params['dir'] ?? 'asc') === 'DESC' ? 'DESC' : 'ASC';

        $where    = '';
        $bindArgs = [];
        if ($q !== '') {
            $where      = 'WHERE email LIKE ?';
            $bindArgs[] = '%' . $q . '%';
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM users {$where}");
        $countStmt->execute($bindArgs);
        $total      = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * self::PER_PAGE;

        $stmt = $this->db->prepare(
            "SELECT id, email, role, active, suspended_at FROM users {$where} ORDER BY `{$sort}` {$dir} LIMIT " . self::PER_PAGE . " OFFSET {$offset}"
        );
        $stmt->execute($bindArgs);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->view->render($response, 'users/list.twig', [
            'title'           => 'Users',
            'breadcrumbs'     => [['label' => 'Users']],
            'users'           => $users,
            'current_user_id' => $this->auth->getUser()?->get('id'),
            'q'               => $q,
            'sort'            => strtolower($sort),
            'dir'             => strtolower($dir),
            'page'            => $page,
            'total'           => $total,
            'total_pages'     => $totalPages,
            'pages'           => self::paginationRange($page, $totalPages),
        ]);
    }

    /**
     * Renders the edit form for a single user, looked up by UUID.
     *
     * @param  Request               $request
     * @param  Response              $response
     * @param  array<string, string> $args Route arguments (id).
     * @return Response
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $user = $this->fetchUserRowById((string) $args['id']);

        if ($user === null) {
            return $response->withStatus(404);
        }

        return $this->view->render($response, 'users/edit.twig', [
            'title'       => 'Edit User',
            'breadcrumbs' => [
                ['label' => 'Users', 'url' => $this->adminPanelUrlPrefix . '/users'],
                ['label' => 'Edit'],
            ],
            'editUser'    => $user,
            'user_fields' => $this->userFields,
        ]);
    }

    /**
     * Processes the edit form — updates email, role, active flag and all custom profile fields.
     *
     * User is looked up by UUID; all DB writes use the internal integer ID.
     * Fires the user_updated hook with the target user and the admin who made the change.
     *
     * @param  Request               $request
     * @param  Response              $response
     * @param  array<string, string> $args Route arguments (id).
     * @return Response
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $body  = (array) $request->getParsedBody();
        $admin = $this->auth->getUser();

        $old = $this->fetchUserRowById((string) $args['id']);
        if ($old === null) {
            return $response->withStatus(404);
        }
        $id = (string) $old['id'];

        $newEmail  = trim((string) ($body['email']  ?? ''));
        $newRole   = in_array($body['role'] ?? '', ['user', 'admin'], true) ? $body['role'] : 'user';
        $newActive = isset($body['active']) ? 1 : 0;

        $setCols = ['email = ?', 'role = ?', 'active = ?'];
        $values  = [$newEmail, $newRole, $newActive];

        foreach ($this->userFields as $fieldName => $fieldDef) {
            $setCols[] = "`{$fieldName}` = ?";
            $raw       = $body[$fieldName] ?? null;
            $values[]  = ($raw !== null && $raw !== '') ? (string) $raw : null;
        }

        $values[] = $id;
        $this->db->prepare('UPDATE users SET ' . implode(', ', $setCols) . ' WHERE id = ?')->execute($values);

        $changed = [];
        if ($newEmail  !== (string) $old['email'])  $changed[] = 'email';
        if ($newRole   !== (string) $old['role'])   $changed[] = 'role';
        if ($newActive !== (int)    $old['active']) $changed[] = 'active';
        foreach (array_keys($this->userFields) as $f) {
            if (($body[$f] ?? '') !== (string) ($old[$f] ?? '')) {
                $changed[] = $f;
            }
        }

        if (!empty($changed) && $admin) {
            $target = $this->fetchUser($id);
            if ($target) {
                $this->audit->userUpdated($target, $changed, $admin);
                $this->hooks->emit('user_updated', $target, $changed, $admin);
            }
        }

        $this->flash->add('success', 'User updated.');
        return $response->withHeader('Location', $this->adminPanelUrlPrefix . '/users')->withStatus(302);
    }

    /**
     * Permanently deletes a user account and invalidates all their sessions.
     *
     * An admin cannot delete their own account.
     * User is looked up by UUID; deletion uses the internal integer ID.
     * Fires the user_deleted hook with the target user and the admin.
     *
     * @param  Request               $request
     * @param  Response              $response
     * @param  array<string, string> $args Route arguments (id).
     * @return Response
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $admin = $this->auth->getUser();
        $row   = $this->fetchUserRowById((string) $args['id']);

        if ($row === null) {
            return $response->withStatus(404);
        }
        $id = (string) $row['id'];

        if ($admin?->get('id') === $id) {
            $this->flash->add('danger', 'You cannot delete your own account.');
            return $response->withHeader('Location', $this->adminPanelUrlPrefix . '/users')->withStatus(302);
        }

        $target = $this->fetchUser($id);

        $this->auth->forceLogoutUser($id, 'deleted by admin');
        $this->db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);

        if ($target && $admin) {
            $this->audit->userDeleted($target, $admin);
            $this->hooks->emit('user_deleted', $target, $admin);
        }

        $this->flash->add('success', 'User account deleted.');
        return $response->withHeader('Location', $this->adminPanelUrlPrefix . '/users')->withStatus(302);
    }

    /**
     * Suspends a user and immediately invalidates all their sessions.
     *
     * An admin cannot suspend themselves.
     * User is looked up by UUID; DB writes use the internal integer ID.
     *
     * @param  Request               $request
     * @param  Response              $response
     * @param  array<string, string> $args Route arguments (id).
     * @return Response
     */
    public function suspend(Request $request, Response $response, array $args): Response
    {
        $admin = $this->auth->getUser();
        $row   = $this->fetchUserRowById((string) $args['id']);

        if ($row === null) {
            return $response->withStatus(404);
        }
        $id = (string) $row['id'];

        if ($admin?->get('id') === $id) {
            $this->flash->add('danger', 'You cannot suspend yourself.');
            return $response->withHeader('Location', $this->adminPanelUrlPrefix . '/users')->withStatus(302);
        }

        $this->db->prepare("UPDATE users SET suspended_at = NOW() WHERE id = ?")->execute([$id]);
        $this->auth->forceLogoutUser($id, 'suspended by admin');

        $target = $this->fetchUser($id);
        if ($target && $admin) {
            $this->audit->userSuspended($target, $admin);
            $this->hooks->emit('user_suspended', $target, $admin);
        }

        $this->flash->add('success', 'User suspended and logged out of all devices.');
        return $response->withHeader('Location', $this->adminPanelUrlPrefix . '/users')->withStatus(302);
    }

    /**
     * Removes the suspension from a user, allowing them to log in again.
     *
     * User is looked up by UUID; DB writes use the internal integer ID.
     *
     * @param  Request               $request
     * @param  Response              $response
     * @param  array<string, string> $args Route arguments (id).
     * @return Response
     */
    public function unsuspend(Request $request, Response $response, array $args): Response
    {
        $admin = $this->auth->getUser();
        $row   = $this->fetchUserRowById((string) $args['id']);

        if ($row === null) {
            return $response->withStatus(404);
        }
        $id = (string) $row['id'];

        $this->db->prepare("UPDATE users SET suspended_at = NULL WHERE id = ?")->execute([$id]);

        $target = $this->fetchUser($id);
        if ($target && $admin) {
            $this->audit->userUnsuspended($target, $admin);
            $this->hooks->emit('user_unsuspended', $target, $admin);
        }

        $this->flash->add('success', 'User unsuspended.');
        return $response->withHeader('Location', $this->adminPanelUrlPrefix . '/users')->withStatus(302);
    }

    /**
     * Renders the create-user form.
     *
     * @param  Request  $request
     * @param  Response $response
     * @return Response
     */
    public function createForm(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'users/create.twig', [
            'title'       => 'Create User',
            'breadcrumbs' => [
                ['label' => 'Users', 'url' => $this->adminPanelUrlPrefix . '/users'],
                ['label' => 'Create'],
            ],
            'user_fields' => $this->userFields,
            'input'       => [],
        ]);
    }

    /**
     * Processes the create-user form and creates a new active account.
     *
     * Admin-created accounts are immediately active regardless of the
     * require_activation setting. A UUID is assigned and the user_created
     * audit event is fired.
     *
     * @param  Request  $request
     * @param  Response $response
     * @return Response
     */
    public function create(Request $request, Response $response): Response
    {
        $body  = (array) $request->getParsedBody();
        $admin = $this->auth->getUser();

        $email    = strtolower(trim((string) ($body['email']    ?? '')));
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->view->render($response, 'users/create.twig', [
                'title'       => 'Create User',
                'user_fields' => $this->userFields,
                'error'       => 'Email and password are required.',
                'input'       => $body,
            ]);
        }

        $customFields = [];
        foreach ($this->userFields as $name => $field) {
            $value = trim((string) ($body[$name] ?? ''));
            if ($field['required'] && $value === '') {
                return $this->view->render($response, 'users/create.twig', [
                    'title'       => 'Create User',
                    'user_fields' => $this->userFields,
                    'error'       => $field['label'] . ' is required.',
                    'input'       => $body,
                ]);
            }
            if ($value !== '') {
                $customFields[$name] = $value;
            }
        }

        $role = in_array($body['role'] ?? '', ['user', 'admin'], true) ? $body['role'] : 'user';

        try {
            $user = $this->auth->register($email, $password, array_merge($customFields, [
                'role'   => $role,
                'active' => 1,
            ]));
        } catch (\AuthKit\Exception\AuthException $e) {
            return $this->view->render($response, 'users/create.twig', [
                'title'       => 'Create User',
                'user_fields' => $this->userFields,
                'error'       => $e->getMessage(),
                'input'       => $body,
            ]);
        }

        if (!($user instanceof \AuthKit\User)) {
            return $this->view->render($response, 'users/create.twig', [
                'title'       => 'Create User',
                'user_fields' => $this->userFields,
                'error'       => 'Registration failed.',
                'input'       => $body,
            ]);
        }

        $id = (string) $user->get('id');

        $target = $this->fetchUser($id);
        if ($target && $admin) {
            $this->audit->userCreated($target, $admin);
            $this->hooks->emit('user_created', $target, $admin);
        }

        $this->flash->add('success', 'User ' . $email . ' created.');
        return $response->withHeader('Location', $this->adminPanelUrlPrefix . '/users')->withStatus(302);
    }

    /**
     * Fetches a single user row by ID (UUID), including all custom profile columns.
     *
     * Returns null when no user with that ID exists.
     *
     * @param  string $id User's UUID primary key.
     * @return array<string, mixed>|null
     */
    private function fetchUserRowById(string $id): ?array
    {
        $cols = ['id', 'email', 'role', 'active', 'suspended_at'];
        foreach (array_keys($this->userFields) as $f) {
            $cols[] = "`{$f}`";
        }
        $stmt = $this->db->prepare('SELECT ' . implode(', ', $cols) . ' FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Fetches a user row and wraps it in a User object.
     *
     * Returns null when the user does not exist.
     *
     * @param  int|string $id
     * @return \AuthKit\User|null
     */
    private function fetchUser(int|string $id): ?\AuthKit\User
    {
        $stmt = $this->db->prepare('SELECT id, email, role FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new \AuthKit\User($row) : null;
    }

    /**
     * Computes the visible page numbers for the pagination bar.
     *
     * Always includes the first and last page. Shows a window of ±2 around
     * the current page. Inserts '...' strings between non-adjacent groups.
     *
     * @param  int                    $current Current page number.
     * @param  int                    $total   Total number of pages.
     * @return array<int, int|string>
     */
    private static function paginationRange(int $current, int $total): array
    {
        if ($total <= 9) {
            return range(1, $total);
        }

        $window = range(max(2, $current - 2), min($total - 1, $current + 2));

        $pages = [1];
        if ($window[0] > 2) {
            $pages[] = '...';
        }
        foreach ($window as $p) {
            $pages[] = $p;
        }
        if (end($window) < $total - 1) {
            $pages[] = '...';
        }
        $pages[] = $total;

        return $pages;
    }
}
