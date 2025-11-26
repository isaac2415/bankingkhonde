<?php
// This file is included in all pages after login
?>
<header>
    <nav>
        <div class="logo">BankingKhonde</div>
        <ul class="nav-links">
            <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li><a href="groups.php" class="nav-link">My Groups</a></li>
            <li><a href="loans.php" class="nav-link">Loans</a></li>
            <li><a href="payments.php" class="nav-link">Payments</a></li>
            <li><a href="reports.php" class="nav-link">Reports</a></li>
            <li class="nav-dropdown">
                <a href="#" class="nav-link"><?php echo htmlspecialchars($_SESSION['full_name']); ?> ▼</a>
                <div class="dropdown-content">
                    <a href="profile.php">Profile</a>
                    <?php if ($_SESSION['role'] === 'treasurer'): ?>
                    <a href="groups.php?action=create">Create Group</a>
                    <?php endif; ?>
                    <a href="groups.php?action=join">Join Group</a>
                    <a href="../logout.php">Logout</a>
                </div>
            </li>
        </ul>
    </nav>
</header>

<style>
.nav-dropdown {
    position: relative;
}

.dropdown-content {
    display: none;
    position: absolute;
    right: 0;
    background: white;
    min-width: 200px;
    box-shadow: 0 8px 16px rgba(0,0,0,0.1);
    border-radius: 5px;
    z-index: 1000;
}

.dropdown-content a {
    display: block;
    padding: 0.75rem 1rem;
    color: #333;
    text-decoration: none;
    border-bottom: 1px solid #eee;
}

.dropdown-content a:hover {
    background: #f8f9fa;
}

.dropdown-content a:last-child {
    border-bottom: none;
}

.nav-dropdown:hover .dropdown-content {
    display: block;
}
</style>