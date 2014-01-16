<?
	function _localCleanup() {
		// Remove the package directory, we do it backwards because the "deepest" files are last
		$contents = @array_reverse(BigTree::directoryContents(SERVER_ROOT."cache/package/"));
		foreach ((array)$contents as $file) {
			@unlink($file);
			@rmdir($file);
		}
		@rmdir(SERVER_ROOT."cache/package/");
	}

	// See if we've hit post_max_size
	if (!$_POST["_bigtree_post_check"]) {
		$_SESSION["bigtree_admin"]["post_max_hit"] = true;
		BigTree::redirect($_SERVER["HTTP_REFERER"]);
	}
	
	// Make sure an upload succeeded
	$error = $_FILES["file"]["error"];
	if ($error == 1 || $error == 2) {
		$_SESSION["upload_error"] = "The file you uploaded is too large.  You may need to edit your php.ini to upload larger files.";
	} elseif ($error == 3) {
		$_SESSION["upload_error"] = "File upload failed.";
	}
	
	if ($error) {
		BigTree::redirect(DEVELOPER_ROOT."foundry/install/");
	}
	
	// We've at least got the file now, unpack it and see what's going on.
	$file = $_FILES["file"]["tmp_name"];
	if (!$file) {
		$_SESSION["upload_error"] = "File upload failed.";
		BigTree::redirect(DEVELOPER_ROOT."foundry/install/");
	}
	
	if (!is_writable(SERVER_ROOT."cache/")) {
?>
<div class="container">
	<section>
		<h3>Error</h3>
		<p>Your cache/ directory must be writable.</p>
	</section>
</div>
<?
		$admin->stop();
	}
	
	// Clean up existing area
	_localCleanup();
	$cache_root = SERVER_ROOT."cache/package/";
	if (!file_exists($cache_root)) {
		mkdir($cache_root);
	}
	// Unzip the package
	include BigTree::path("inc/lib/pclzip.php");
	$zip = new PclZip($file);
	$files = $zip->extract(PCLZIP_OPT_PATH,$cache_root);
	if (!$files) {
		_localCleanup();
		$_SESSION["upload_error"] = "The zip file uploaded was corrupt.";
		BigTree::redirect(DEVELOPER_ROOT."foundry/install/");
	}
	
	// Read the manifest
	$json = json_decode(file_get_contents($cache_root."manifest.json"),true);
	// Make sure it's legit
	if ($json["type"] != "package" || !$json["id"] || !$json["title"]) {
		_localCleanup();
		$_SESSION["upload_error"] = "The zip file uploaded does not appear to be a BigTree package.";
		BigTree::redirect(DEVELOPER_ROOT."foundry/install/");
	}
	
	$instrutions = $json["instructions"];
	$install_code = $json["install_code"];

	// Check for template collisions
	foreach ($json["templates"] as $template) {
		if (sqlrows(sqlquery("SELECT * FROM bigtree_templates WHERE id = '".sqlescape($template["id"])."'"))) {
			$warnings[] = "A template already exists with the id &ldquo;".$template["id"]."&rdquo; &mdash; the template will be overwritten.";
		}
	}
	// Check for callout collisions
	foreach ($json["callouts"] as $callout) {
		if (sqlrows(sqlquery("SELECT * FROM bigtree_callouts WHERE id = '".sqlescape($callout["id"])."'"))) {
			$warnings[] = "A sidelet already exists with the id &ldquo;".$callout["id"]."&rdquo; &mdash; the sidelet will be overwritten.";
		}
	}
	// Check for settings collisions
	foreach ($json["settings"] as $setting) {
		if (sqlrows(sqlquery("SELECT * FROM bigtree_settings WHERE id = '".sqlescape($setting["id"])."'"))) {
			$warnings[] = "A setting already exists with the id &ldquo;".$setting["id"]."&rdquo; &mdash; the setting will be overwritten.";
		}
	}
	// Check for feed collisions
	foreach ($json["feeds"] as $feed) {
		if (sqlrows(sqlquery("SELECT * FROM bigtree_feeds WHERE route = '".sqlescape($feed["route"])."'"))) {
			$warnings[] = "A feed already exists with the route &ldquo;".$feed["route"]."&rdquo; &mdash; the feed will be overwritten.";
		}
	}
	// Check for field type collisions
	foreach ($json["field_types"] as $type) {
		if (sqlrows(sqlquery("SELECT * FROM bigtree_field_types WHERE id = '".sqlescape($type["id"])."'"))) {
			$warnings[] = "A field type already exists with the id &ldquo;".$type["id"]."&rdquo; &mdash; the field type will be overwritten.";
		}
	}
	// Check for table collisions
	foreach ($json["sql"] as $command) {
		if (substr($command,0,14) == "CREATE TABLE `") {
			$table = substr($command,14);
			$table = substr($table,0,strpos($table,"`"));
			if (sqlrows(sqlquery("SHOW TABLES LIKE '$table'"))) {
				$warnings[] = "A table named &ldquo;$table&rdquo; already exists &mdash; the table will be overwritten.";
			}
		}
	}
	// Check file permissions and collisions
	foreach ($json["files"] as $file) {
		if (!BigTree::isDirectoryWritable(SERVER_ROOT.$file)) {
			$errors[] = "Cannot write to $file &mdash; please make the root directory writable.";
		} elseif (file_exists(SERVER_ROOT.$file)) {
			$warnings[] = "A file already exists at $file &mdash; the file will be overwritten.";
		}
	}
?>
<div class="container">
	<summary>
		<h2>
			<?=$json["title"]?>
			<small>by <?=$json["author"]?></small>
		</h2>
	</summary>
	<section>
		<?
			if (count($instructions) && $instructions["pre"]) {
		?>
		<h3>Instructions</h3>
		<p><?=nl2br(htmlspecialchars(base64_decode($instructions["pre"])))?></p>
		<br />
		<hr />
		<?
			}

			if ($install_code) {
		?>
		<h3>Post Install Code</h3>
		<p>The following code will be run after the package is finished installing:</p>
		<pre><code class="language-php"><?=htmlspecialchars(ltrim(rtrim(base64_decode($install_code),"?>"),"<?"))?></code></pre>
		<br /><br />
		<hr />
		<?
			}

			if (count($warnings)) {
		?>
		<h3>Warnings</h3>
		<ul>
			<? foreach ($warnings as $w) { ?>
			<li><?=$w?></li>
			<? } ?>
		</ul>
		<?
			}
			
			if (count($errors)) {
		?>
		<h3>Errors</h3>
		<ul>
			<? foreach ($errors as $e) { ?>
			<li><?=$e?></li>
			<? } ?>
		</ul>
		<p><strong>ERRORS OCCURRED!</strong> &mdash; Please correct all errors.  You may not import this module while errors persist.</p>
		<?
			}
			
			if (!count($warnings) && !count($errors)) {
		?>
		<p>Package is ready to be installed. No problems found.</p>
		<?
			}
		?>
	</section>
	<? if (!count($errors)) { ?>
	<footer>
		<a href="<?=DEVELOPER_ROOT?>foundry/install/process/" class="button blue">Install</a>
	</footer>
	<? } ?>
</div>