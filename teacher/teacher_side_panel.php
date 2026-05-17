<?php
if (!isset($pdo)) {
	require_once dirname(__DIR__) . '/config/db.php';
	$pdo = db_connect();
}
$school_name = get_system_setting($pdo, 'school_name', 'Malolos Marine Fishery School and Laboratory');
$school_logo = get_system_setting($pdo, 'school_logo', '/assets/images/school_logo.png');
?>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
	<!-- MOBILE HEADER (Close Button) -->
	<div class="sidebar-header">
		<button class="close-btn" id="close-sidebar">&times;</button>
	</div>


	<!-- NAVIGATION -->



	<!-- NAVIGATION -->
	<nav class="sidebar-nav">
		<a href="<?= url_for('/teacher/dashboard.php') ?>">
			<span class="icon">🏠</span>
			<span class="text">Dashboard</span>
		</a>
		<a href="<?= url_for('/registration_final.php') ?>">
			<span class="icon">📝</span>
			<span class="text">New Registration</span>
		</a>

		<a href="<?= url_for('/teacher/my_classes.php') ?>">
			<span class="icon">📚</span>
			<span class="text">My Classes</span>
		</a>
		<a href="<?= url_for('/teacher/advisory_list.php') ?>">
			<span class="icon">🧑‍🏫</span>
			<span class="text">Advisory List</span>
		</a>
		<a href="<?= url_for('/teacher/reports.php') ?>">
			<span class="icon">📊</span>
			<span class="text">Reports</span>
		</a>

		<a href="<?= url_for('/teacher/books.php') ?>">
			<span class="icon">📚</span>
			<span class="text">Books </span>
		</a>

		<!-- LOGOUT -->
		<a href="<?= url_for('/logout.php') ?>"
			style="margin-top: 40px; border-top: 1px solid rgba(0,0,0,0.05); padding-top: 10px; color: #ef4444;">
			<span class="icon">🚪</span>
			<span class="text">Logout</span>
		</a>
	</nav>
</aside>

<script>
	// Auto-highlight active link
	document.addEventListener('DOMContentLoaded', () => {
		const currentPath = window.location.pathname;
		const links = document.querySelectorAll('.sidebar-nav a');

		links.forEach(link => {
			try {
				const linkUrl = new URL(link.href, window.location.origin);
				// Exact match or folder match
				if (currentPath.includes(linkUrl.pathname) && linkUrl.pathname.length > 5) {
					link.classList.add('active');
				}
			} catch (e) {}
		});
	});
</script>

<!-- Mobile Overlay -->
<div class="sidebar-overlay"></div>