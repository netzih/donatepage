<?php
/**
 * Admin - User Management
 * 
 * Manage admin users with role-based access control.
 * Super admins can edit all users.
 * Admins can edit non-super-admin users.
 * Users can only edit their own profile.
 */

session_start();
require_once __DIR__ . '/../includes/functions.php';
requireAdmin();

$settings = getAllSettings();
$success = '';
$error = '';
$csrfToken = generateCsrfToken();

$currentUserId = $_SESSION['admin_id'];
$currentRole = getCurrentRole();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        try {
            switch ($action) {
                case 'add':
                    // Only admins and super admins can add users
                    if (!isAdmin()) {
                        throw new Exception('You do not have permission to add users.');
                    }
                    
                    $username = trim($_POST['username'] ?? '');
                    $email = trim($_POST['email'] ?? '');
                    $password = $_POST['password'] ?? '';
                    $role = $_POST['role'] ?? 'user';
                    
                    // Validate inputs
                    if (empty($username) || empty($password)) {
                        throw new Exception('Username and password are required.');
                    }
                    
                    if (strlen($password) < 8) {
                        throw new Exception('Password must be at least 8 characters.');
                    }
                    
                    // Non-super-admins cannot create super admins
                    if ($role === 'super_admin' && !isSuperAdmin()) {
                        throw new Exception('Only super admins can create other super admins.');
                    }
                    
                    // Check if username already exists
                    $existing = db()->fetch("SELECT id FROM admins WHERE username = ?", [$username]);
                    if ($existing) {
                        throw new Exception('Username already exists.');
                    }
                    
                    // Create user
                    db()->insert('admins', [
                        'username' => $username,
                        'email' => $email,
                        'password' => password_hash($password, PASSWORD_BCRYPT),
                        'role' => $role
                    ]);
                    
                    $success = 'User created successfully!';
                    logSecurityEvent('user_created', ['username' => $username, 'role' => $role], 'info');
                    break;
                    
                case 'edit':
                    $userId = (int)($_POST['user_id'] ?? 0);
                    $user = db()->fetch("SELECT * FROM admins WHERE id = ?", [$userId]);
                    
                    if (!$user) {
                        throw new Exception('User not found.');
                    }
                    
                    if (!canEditUser($userId, $user['role'])) {
                        throw new Exception('You do not have permission to edit this user.');
                    }
                    
                    $updateData = [];
                    
                    // Username update (if changed and allowed)
                    $newUsername = trim($_POST['username'] ?? '');
                    if (!empty($newUsername) && $newUsername !== $user['username']) {
                        $existing = db()->fetch("SELECT id FROM admins WHERE username = ? AND id != ?", [$newUsername, $userId]);
                        if ($existing) {
                            throw new Exception('Username already exists.');
                        }
                        $updateData['username'] = $newUsername;
                    }
                    
                    // Email update
                    $updateData['email'] = trim($_POST['email'] ?? '');
                    
                    // Password update (only if provided)
                    $newPassword = $_POST['password'] ?? '';
                    if (!empty($newPassword)) {
                        if (strlen($newPassword) < 8) {
                            throw new Exception('Password must be at least 8 characters.');
                        }
                        $updateData['password'] = password_hash($newPassword, PASSWORD_BCRYPT);
                    }
                    
                    // Role update (only if user has permission)
                    $newRole = $_POST['role'] ?? $user['role'];
                    if ($newRole !== $user['role']) {
                        // Only super admins can change roles to/from super_admin
                        if (($newRole === 'super_admin' || $user['role'] === 'super_admin') && !isSuperAdmin()) {
                            throw new Exception('Only super admins can modify super admin roles.');
                        }
                        // Regular admins can only set user/admin roles
                        if (!isAdmin()) {
                            throw new Exception('You cannot change user roles.');
                        }
                        $updateData['role'] = $newRole;
                    }
                    
                    if (!empty($updateData)) {
                        db()->update('admins', $updateData, 'id = ?', [$userId]);
                        
                        // If editing self, update session
                        if ($userId == $currentUserId) {
                            if (isset($updateData['username'])) {
                                $_SESSION['admin_username'] = $updateData['username'];
                            }
                            if (isset($updateData['role'])) {
                                $_SESSION['admin_role'] = $updateData['role'];
                            }
                        }
                        
                        $success = 'User updated successfully!';
                        logSecurityEvent('user_updated', ['user_id' => $userId], 'info');
                    }
                    break;
                    
                case 'delete':
                    $userId = (int)($_POST['user_id'] ?? 0);
                    $user = db()->fetch("SELECT * FROM admins WHERE id = ?", [$userId]);
                    
                    if (!$user) {
                        throw new Exception('User not found.');
                    }
                    
                    if (!canDeleteUser($userId, $user['role'])) {
                        if ($userId == $currentUserId) {
                            throw new Exception('You cannot delete your own account.');
                        }
                        throw new Exception('You do not have permission to delete this user.');
                    }
                    
                    db()->execute("DELETE FROM admins WHERE id = ?", [$userId]);
                    $success = 'User deleted successfully!';
                    logSecurityEvent('user_deleted', ['user_id' => $userId, 'username' => $user['username']], 'warning');
                    break;
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Fetch all users
$users = db()->fetchAll("SELECT * FROM admins ORDER BY role DESC, username ASC");

// Define role labels
$roleLabels = [
    'super_admin' => 'Super Admin',
    'admin' => 'Admin',
    'user' => 'User'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - Admin</title>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/admin/admin-style.css">
</head>
<body>
    <div class="admin-layout">
        <?php $currentPage = 'users'; include 'includes/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="content-header">
                <h1>User Management</h1>
                <p>Manage admin users and their permissions</p>
            </header>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?= h($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>
            
            <section class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="margin: 0; padding: 0; border: none;">All Users</h2>
                    <?php if (isAdmin()): ?>
                    <button class="btn btn-primary" onclick="openAddModal()">+ Add User</button>
                    <?php endif; ?>
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <strong><?= h($user['username']) ?></strong>
                                    <?php if ($user['id'] == $currentUserId): ?>
                                        <span class="you-badge">YOU</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?= h($user['email'] ?: '—') ?></td>
                            <td>
                                <span class="role-badge role-<?= h($user['role'] ?? 'user') ?>">
                                    <?= h($roleLabels[$user['role'] ?? 'user'] ?? 'User') ?>
                                </span>
                            </td>
                            <td><?= date('M j, Y', strtotime($user['created_at'])) ?></td>
                            <td>
                                <div class="action-buttons">
                                    <?php if (canEditUser($user['id'], $user['role'])): ?>
                                    <button class="btn btn-secondary btn-sm" 
                                            onclick="openEditModal(<?= h(json_encode($user)) ?>)">
                                        Edit
                                    </button>
                                    <?php endif; ?>
                                    <?php if (canDeleteUser($user['id'], $user['role'])): ?>
                                    <button class="btn btn-danger btn-sm" 
                                            onclick="confirmDelete(<?= $user['id'] ?>, '<?= h($user['username']) ?>')">
                                        Delete
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            
            <!-- Role Legend -->
            <section class="card">
                <h2>Role Permissions</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Role</th>
                            <th>Permissions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="role-badge role-super_admin">Super Admin</span></td>
                            <td>Full access: manage all users, settings, donations, campaigns, and integrations</td>
                        </tr>
                        <tr>
                            <td><span class="role-badge role-admin">Admin</span></td>
                            <td>Can manage donations, campaigns, and non-super-admin users</td>
                        </tr>
                        <tr>
                            <td><span class="role-badge role-user">User</span></td>
                            <td>Can add donations and edit their own profile/password</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
    
    <!-- Add User Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New User</h2>
                <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label for="add-username">Username *</label>
                    <input type="text" id="add-username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="add-email">Email</label>
                    <input type="email" id="add-email" name="email">
                </div>
                
                <div class="form-group">
                    <label for="add-password">Password *</label>
                    <input type="password" id="add-password" name="password" required minlength="8">
                    <small>Minimum 8 characters</small>
                </div>
                
                <div class="form-group">
                    <label for="add-role">Role *</label>
                    <select id="add-role" name="role" required>
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                        <?php if (isSuperAdmin()): ?>
                        <option value="super_admin">Super Admin</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit User</h2>
                <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" id="edit-user-id">
                
                <div class="form-group">
                    <label for="edit-username">Username</label>
                    <input type="text" id="edit-username" name="username" required>
                </div>
                
                <div class="form-group">
                    <label for="edit-email">Email</label>
                    <input type="email" id="edit-email" name="email">
                </div>
                
                <div class="form-group">
                    <label for="edit-password">New Password</label>
                    <input type="password" id="edit-password" name="password" minlength="8">
                    <small>Leave blank to keep current password</small>
                </div>
                
                <div class="form-group" id="edit-role-group">
                    <label for="edit-role">Role</label>
                    <select id="edit-role" name="role">
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                        <?php if (isSuperAdmin()): ?>
                        <option value="super_admin">Super Admin</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Confirm Delete</h2>
                <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <p>Are you sure you want to delete user <strong id="delete-username"></strong>?</p>
            <p style="color: #dc3545;">This action cannot be undone.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" id="delete-user-id">
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete User</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
    const currentUserId = <?= $currentUserId ?>;
    const currentRole = '<?= h($currentRole) ?>';
    
    function openAddModal() {
        document.getElementById('addModal').classList.add('active');
    }
    
    function openEditModal(user) {
        document.getElementById('edit-user-id').value = user.id;
        document.getElementById('edit-username').value = user.username;
        document.getElementById('edit-email').value = user.email || '';
        document.getElementById('edit-role').value = user.role || 'user';
        
        // Hide role selector if user is editing themselves (unless super admin)
        const roleGroup = document.getElementById('edit-role-group');
        if (user.id == currentUserId && currentRole !== 'super_admin') {
            roleGroup.style.display = 'none';
        } else {
            roleGroup.style.display = 'block';
        }
        
        document.getElementById('editModal').classList.add('active');
    }
    
    function confirmDelete(userId, username) {
        document.getElementById('delete-user-id').value = userId;
        document.getElementById('delete-username').textContent = username;
        document.getElementById('deleteModal').classList.add('active');
    }
    
    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
    }
    
    // Close modal when clicking outside
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.active').forEach(modal => {
                modal.classList.remove('active');
            });
        }
    });
    </script>
</body>
</html>
