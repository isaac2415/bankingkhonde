<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'view';

// Get current user data
$query = "SELECT * FROM `users` WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['error'] = "User not found";
    header("Location: dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? $action;
    
    switch ($action) {
        case 'update_profile':
            updateProfile($db, $user_id);
            break;
        case 'change_password':
            changePassword($db, $user_id);
            break;
        case 'update_preferences':
            updatePreferences($db, $user_id);
            break;
    }
}

function updateProfile($db, $user_id) {
    try {
        $required_fields = ['full_name', 'email', 'phone'];
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
                throw new Exception("Field '$field' is required");
            }
        }
        
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format");
        }
        
        // Check if email already exists (excluding current user)
    $query = "SELECT id FROM `users` WHERE email = ? AND id != ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$email, $user_id]);
        
        if ($stmt->fetch()) {
            throw new Exception("Email already exists. Please use a different email.");
        }
        
    $query = "UPDATE `users` SET full_name = ?, email = ?, phone = ? WHERE id = ?";
        $stmt = $db->prepare($query);
        
        if ($stmt->execute([$full_name, $email, $phone, $user_id])) {
            // Update session data
            $_SESSION['full_name'] = $full_name;
            $_SESSION['email'] = $email;
            
            $_SESSION['success'] = "Profile updated successfully!";
        } else {
            throw new Exception("Failed to update profile");
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    
    header("Location: profile.php");
    exit();
}

function changePassword($db, $user_id) {
    try {
        $required_fields = ['current_password', 'new_password', 'confirm_password'];
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                throw new Exception("All password fields are required");
            }
        }
        
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Verify current password
    $query = "SELECT password FROM `users` WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$user_id]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user_data || !password_verify($current_password, $user_data['password'])) {
            throw new Exception("Current password is incorrect");
        }
        
        // Validate new password
        if (strlen($new_password) < 6) {
            throw new Exception("New password must be at least 6 characters long");
        }
        
        if ($new_password !== $confirm_password) {
            throw new Exception("New passwords do not match");
        }
        
        // Update password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    $query = "UPDATE `users` SET password = ? WHERE id = ?";
        $stmt = $db->prepare($query);
        
        if ($stmt->execute([$hashed_password, $user_id])) {
            $_SESSION['success'] = "Password changed successfully!";
        } else {
            throw new Exception("Failed to change password");
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    
    header("Location: profile.php");
    exit();
}

function updatePreferences($db, $user_id) {
    try {
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
        $loan_alerts = isset($_POST['loan_alerts']) ? 1 : 0;
        $payment_reminders = isset($_POST['payment_reminders']) ? 1 : 0;
        
        // In a real application, you'd have a user_preferences table
        // For now, we'll store in session or extend users table
        $_SESSION['preferences'] = [
            'email_notifications' => $email_notifications,
            'sms_notifications' => $sms_notifications,
            'loan_alerts' => $loan_alerts,
            'payment_reminders' => $payment_reminders
        ];
        
        $_SESSION['success'] = "Preferences updated successfully!";
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    
    header("Location: profile.php");
    exit();
}

// Get user statistics
$query = "SELECT
          COUNT(DISTINCT gm.group_id) as total_groups,
          COUNT(DISTINCT p.id) as total_payments,
          COALESCE(SUM(p.amount), 0) as total_contributions,
          COUNT(DISTINCT l.id) as total_loans,
          COUNT(DISTINCT CASE WHEN l.status = 'approved' THEN l.id END) as approved_loans
          FROM `users` u
          LEFT JOIN `group_members` gm ON u.id = gm.user_id AND gm.status = 'active'
          LEFT JOIN `payments` p ON u.id = p.user_id AND p.status = 'paid'
          LEFT JOIN `loans` l ON u.id = l.user_id
          WHERE u.id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$user_id]);
$user_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get user's groups
$query = "SELECT g.*, gm.joined_at 
          FROM `groups` g
          JOIN `group_members` gm ON g.id = gm.group_id
          WHERE gm.user_id = ? AND gm.status = 'active'
          ORDER BY gm.joined_at DESC";
$stmt = $db->prepare($query);
$stmt->execute([$user_id]);
$user_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent activity
$query = "(
    SELECT 'payment' as type, p.amount, p.payment_date as date, g.name as group_name, NULL as status
    FROM `payments` p
    JOIN `groups` g ON p.group_id = g.id
    WHERE p.user_id = ? AND p.status = 'paid'
    ORDER BY p.payment_date DESC
    LIMIT 5
) UNION ALL (
    SELECT 'loan' as type, l.amount, l.applied_date as date, g.name as group_name, l.status
    FROM `loans` l
    JOIN `groups` g ON l.group_id = g.id
    WHERE l.user_id = ?
    ORDER BY l.applied_date DESC
    LIMIT 5
) ORDER BY date DESC LIMIT 10";
$stmt = $db->prepare($query);
$stmt->execute([$user_id, $user_id]);
$recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - BankingKhonde</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            margin: 0 auto 1rem;
        }
        
        .profile-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin: 2rem 0;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
            display: block;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #666;
        }
        
        .tab-navigation {
            display: flex;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 2rem;
        }
        
        .tab-button {
            padding: 1rem 2rem;
            background: none;
            border: none;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .tab-button.active {
            border-bottom-color: #667eea;
            color: #667eea;
            font-weight: bold;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .activity-item {
            padding: 1rem;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }
        
        .activity-payment {
            background: #28a745;
        }
        
.activity-loan {
    background-color: #e3f2fd;
    color: #1565c0;
}

.password-strength-bar {
    height: 5px;
    background-color: #eee;
    margin: 5px 0;
    border-radius: 3px;
    position: relative;
}

.strength-weak {
    background: linear-gradient(to right, #ff4444 33%, #eee 33%);
}

.strength-medium {
    background: linear-gradient(to right, #ffa000 66%, #eee 66%);
}

.strength-strong {
    background: linear-gradient(to right, #00c853 100%, #eee 100%);
}        .password-strength {
            height: 5px;
            background: #e9ecef;
            border-radius: 5px;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
        }
        
        .strength-weak { background: #dc3545; width: 33%; }
        .strength-medium { background: #ffc107; width: 66%; }
        .strength-strong { background: #28a745; width: 100%; }
        
        @media (max-width: 768px) {
            .tab-navigation {
                flex-direction: column;
            }
            
            .tab-button {
                text-align: left;
                border-bottom: 1px solid #e9ecef;
                border-left: 3px solid transparent;
            }
            
            .tab-button.active {
                border-left-color: #667eea;
                border-bottom-color: #e9ecef;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="profile-header">
        <div class="container">
            <div class="profile-avatar">
                <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
            </div>
            <h1 style="text-align: center; margin: 0;"><?php echo htmlspecialchars($user['full_name']); ?></h1>
            <p style="text-align: center; opacity: 0.8; margin: 0.5rem 0 0 0;">
                @<?php echo htmlspecialchars($user['username']); ?> • 
                <?php echo ucfirst($user['role']); ?>
            </p>
        </div>
    </div>

    <main class="container">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="message message-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="message message-error"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <!-- User Statistics -->
        <div class="profile-stats">
            <div class="stat-card">
                <span class="stat-number"><?php echo $user_stats['total_groups'] ?? 0; ?></span>
                <span class="stat-label">Groups</span>
            </div>
            <div class="stat-card">
                <span class="stat-number"><?php echo $user_stats['total_payments'] ?? 0; ?></span>
                <span class="stat-label">Payments Made</span>
            </div>
            <div class="stat-card">
                <span class="stat-number">K <?php echo number_format($user_stats['total_contributions'] ?? 0, 0); ?></span>
                <span class="stat-label">Total Contributed</span>
            </div>
            <div class="stat-card">
                <span class="stat-number"><?php echo $user_stats['approved_loans'] ?? 0; ?></span>
                <span class="stat-label">Approved Loans</span>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="tab-navigation">
            <button class="tab-button active" onclick="showTab('profile')">Profile Information</button>
            <button class="tab-button" onclick="showTab('security')">Security</button>
            <button class="tab-button" onclick="showTab('preferences')">Preferences</button>
            <button class="tab-button" onclick="showTab('activity')">Recent Activity</button>
        </div>

        <!-- Profile Information Tab -->
        <div id="profile-tab" class="tab-content active">
            <div class="card">
                <h3>Profile Information</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label for="full_name">Full Name</label>
                            <input type="text" id="full_name" name="full_name" 
                                   value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                            <small style="color: #666;">Username cannot be changed</small>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" 
                                   value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" 
                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="role">Account Type</label>
                        <input type="text" id="role" value="<?php echo ucfirst($user['role']); ?>" disabled>
                        <small style="color: #666;">
                            <?php if ($user['role'] === 'treasurer'): ?>
                                You can create and manage groups
                            <?php else: ?>
                                You can join groups and participate in activities
                            <?php endif; ?>
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label>Member Since</label>
                        <input type="text" value="<?php echo date('F j, Y', strtotime($user['created_at'])); ?>" disabled>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Update Profile</button>
                </form>
            </div>

            <!-- My Groups Section -->
            <div class="card" style="margin-top: 2rem;">
                <h3>My Groups</h3>
                <?php if (empty($user_groups)): ?>
                    <p>You haven't joined any groups yet.</p>
                    <a href="groups.php?action=join" class="btn btn-primary">Join a Group</a>
                    <?php if ($user['role'] === 'treasurer'): ?>
                        <a href="groups.php?action=create" class="btn btn-success" style="margin-left: 1rem;">Create a Group</a>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Group Name</th>
                                    <th>Role</th>
                                    <th>Contribution</th>
                                    <th>Joined Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($user_groups as $group): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($group['name']); ?></td>
                                    <td>
                                        <?php if ($group['treasurer_id'] == $user_id): ?>
                                            <span class="status-badge status-approved">Treasurer</span>
                                        <?php else: ?>
                                            <span class="status-badge status-pending">Member</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>K <?php echo number_format($group['contribution_amount'], 2); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($group['joined_at'])); ?></td>
                                    <td>
                                        <a href="groups.php?action=view&id=<?php echo $group['id']; ?>" class="btn btn-primary btn-sm">View</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Security Tab -->
        <div id="security-tab" class="tab-content">
            <div class="card">
                <h3>Change Password</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" required 
                               onkeyup="checkPasswordStrength(this.value)">
                        <div class="password-strength">
                            <div class="password-strength-bar" id="password-strength-bar"></div>
                        </div>
                        <small style="color: #666;">Password must be at least 6 characters long</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
            </div>

            <div class="card" style="margin-top: 2rem;">
                <h3>Security Information</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <strong>Last Login:</strong><br>
                        <span style="color: #666;"><?php echo date('F j, Y g:i A'); ?> (Current session)</span>
                    </div>
                    <div>
                        <strong>Account Created:</strong><br>
                        <span style="color: #666;"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preferences Tab -->
        <div id="preferences-tab" class="tab-content">
            <div class="card">
                <h3>Notification Preferences</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="update_preferences">
                    
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="email_notifications" 
                                   <?php echo isset($_SESSION['preferences']['email_notifications']) && $_SESSION['preferences']['email_notifications'] ? 'checked' : 'checked'; ?>>
                            <span>Email Notifications</span>
                        </label>
                        <small style="color: #666;">Receive important updates via email</small>
                    </div>
                    
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="sms_notifications"
                                   <?php echo isset($_SESSION['preferences']['sms_notifications']) && $_SESSION['preferences']['sms_notifications'] ? 'checked' : 'checked'; ?>>
                            <span>SMS Notifications</span>
                        </label>
                        <small style="color: #666;">Receive payment reminders via SMS</small>
                    </div>
                    
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="loan_alerts"
                                   <?php echo isset($_SESSION['preferences']['loan_alerts']) && $_SESSION['preferences']['loan_alerts'] ? 'checked' : 'checked'; ?>>
                            <span>Loan Application Alerts</span>
                        </label>
                        <small style="color: #666;">Get notified when loan applications are submitted</small>
                    </div>
                    
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" name="payment_reminders"
                                   <?php echo isset($_SESSION['preferences']['payment_reminders']) && $_SESSION['preferences']['payment_reminders'] ? 'checked' : 'checked'; ?>>
                            <span>Payment Reminders</span>
                        </label>
                        <small style="color: #666;">Receive reminders for upcoming payments</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Save Preferences</button>
                </form>
            </div>
        </div>

        <!-- Password Tab -->
        <div id="password-tab" class="tab-content">
            <div class="card">
                <h3>Change Password</h3>
                <form method="POST" action="profile.php" class="form">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password" 
                               required minlength="6" onkeyup="checkPasswordStrength(this.value)">
                        <div class="password-strength-bar" id="password-strength-bar"></div>
                        <small>Password must be at least 6 characters long</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
            </div>
        </div>

        <!-- Activity Tab -->
        <div id="activity-tab" class="tab-content">
            <div class="card">
                <h3>Recent Activity</h3>
                <?php if (empty($recent_activity)): ?>
                    <p>No recent activity to display.</p>
                <?php else: ?>
                    <div style="max-height: 500px; overflow-y: auto;">
                        <?php foreach ($recent_activity as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon <?php echo $activity['type'] === 'payment' ? 'activity-payment' : 'activity-loan'; ?>">
                                    <?php echo $activity['type'] === 'payment' ? '💰' : '💳'; ?>
                                </div>
                                <div style="flex: 1;">
                                    <strong>
                                        <?php if ($activity['type'] === 'payment'): ?>
                                            Payment of K <?php echo number_format($activity['amount'], 2); ?>
                                        <?php else: ?>
                                            Loan Application - K <?php echo number_format($activity['amount'], 2); ?>
                                            <span class="status-badge status-<?php echo $activity['status']; ?>" style="margin-left: 0.5rem;">
                                                <?php echo ucfirst($activity['status']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </strong>
                                    <div style="color: #666; font-size: 0.9rem;">
                                        In <?php echo htmlspecialchars($activity['group_name']); ?> • 
                                        <?php echo date('M j, Y g:i A', strtotime($activity['date'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Activate selected tab button
            event.currentTarget.classList.add('active');
        }
        
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('password-strength-bar');
            let strength = 0;
            
            if (password.length >= 6) strength++;
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            strengthBar.className = 'password-strength-bar';
            if (strength <= 2) {
                strengthBar.classList.add('strength-weak');
            } else if (strength <= 4) {
                strengthBar.classList.add('strength-medium');
            } else {
                strengthBar.classList.add('strength-strong');
            }
        }
        
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const passwordForm = document.querySelector('form[action="change_password"]');
            if (passwordForm) {
                passwordForm.addEventListener('submit', function(e) {
                    const newPassword = document.getElementById('new_password').value;
                    const confirmPassword = document.getElementById('confirm_password').value;
                    
                    if (newPassword !== confirmPassword) {
                        e.preventDefault();
                        alert('New passwords do not match!');
                        return false;
                    }
                    
                    if (newPassword.length < 6) {
                        e.preventDefault();
                        alert('Password must be at least 6 characters long!');
                        return false;
                    }
                });
            }
        });
    </script>
</body>
</html>