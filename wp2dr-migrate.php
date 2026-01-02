<?php

// Security check: CLI only
PHP_SAPI === 'cli' || exit;

/**
 * WordPress to Drupal 11 Migration Script.
 *
 * Tested with Drupal 11 CMS + Blog recipe
 * (Drush and Media module are included by default).
 *
 * After installing Drupal CMS, you need to:
 *   - Enable comments and create a comment type (to import comments)
 *   - Create a "Categories" taxonomy (to import categories)
 *   - Configure valid text formats
 *     (Full HTML recommended for content, Basic HTML or Plain text for comments)
 *
 * This script can also work with Drupal Core, but requires manual installation of:
 *   - Drush CLI tool: composer require drush/drush
 *   - Media module: drush en media -y
 *
 * The script facilitates migration of content from a WordPress XML export
 * to a Drupal 11 installation. It supports:
 *   - Posts and pages
 *   - Media (attachments)
 *   - Taxonomy terms (categories and tags)
 *   - Comments
 *   - Automatic field detection where possible
 *
 * This is a standalone, Drush-based migration utility and does not rely
 * on Drupal Migrate API.
 *
 * Usage:
 *   drush scr wp2dr-migrate.php
 *
 * @package   WordPressMigrator
 * @author    Salvatore Noschese
 * @version   2.0
 * @license   MIT
 * @link      https://salvatorenoschese.it
 *
 * === FUNCTION MAP ===
 *
 * DISCOVERY PHASE:
 *   checkDrupal()      - Verify Drupal 11.x version
 *   analyzeXML()       - Parse XML, count entities, detect authors/media
 *   scanEnvironment()  - Detect content types, vocabularies, text formats
 *   validateSystem()   - Check all required modules and configurations
 *
 * CONFIGURATION PHASE:
 *   loadCache()        - Load entity mappings from previous runs
 *   configure()        - Interactive wizard to map WordPress → Drupal
 *   confirmImport()    - Display summary and confirm Live/Dry-run mode
 *
 * EXECUTION PHASE:
 *   importUsers()      - Create/map WordPress authors → Drupal users
 *   importMedia()      - Download and create Media entities from attachments
 *   importTaxonomy()   - Create taxonomy terms for categories/tags
 *   importContent()    - Create nodes (posts/pages) with all references
 *   importComments()   - Create Comment entities linked to posts
 *
 * HELPER METHODS:
 *   detectFields()     - Auto-detect body/excerpt/image fields for bundle
 *   createMedia()      - Download file and create Media entity
 *   cleanContent()     - Remove Gutenberg blocks, replace media URLs
 *   addFeaturedImage() - Link Media entity to node's image field
 *   createComment()    - Create single comment with threading support
 *   extractAlias()     - Preserve WordPress URL aliases
 *   addTaxonomy()      - Attach taxonomy terms to nodes
 *   generateExcerpt()  - Auto-generate excerpt from content
 *
 * UI UTILITIES:
 *   prompt()           - Interactive CLI input
 *   choose()           - Selection menu
 *   progress()         - Progress bar display
 *   header/success/... - Colored console output
 */

// === ENTITY TYPES ===
use Drupal\node\Entity\Node;           // Content nodes (posts, pages)
use Drupal\taxonomy\Entity\Term;       // Taxonomy terms (categories, tags)
use Drupal\media\Entity\Media;         // Media entities (images, documents, video)
use Drupal\file\Entity\File;           // File entities (media source files)
use Drupal\comment\Entity\Comment;     // Comment entities
use Drupal\user\Entity\User;           // User accounts

// === UTILITY CLASSES ===
use Drupal\Component\Utility\Html;     // HTML processing and sanitization
use Drupal\Component\Utility\Unicode;  // Text truncation and Unicode handling

// === SERVICES & INTERFACES ===
use Drupal\Core\File\FileSystemInterface;  // File system operations (download, save)

// === DRUSH CLI ===
use Drush\Exceptions\UserAbortException;   // Graceful script cancellation (Drush-specific)

/**
 * Class WordPressMigrator
 *
 * Main controller class for the migration process.
 * Handles parsing, configuration, and entity creation.
 */
class WordPressMigrator {

	private $xmlFile;
	private $logFile;
	private $cacheFile;

	/**
	 * Data parsed from the WordPress XML file.
	 * @var array
	 */
	private $xml = [
		'domain'      => null,
		'base_url'    => null,
		'authors'     => [],
		'attachments' => [],
		'posts'       => 0,
		'pages'       => 0,
		'categories'  => [],
		'tags'        => [],
		'comments'    => 0,
	];

	/**
	 * Information about the current Drupal environment.
	 * @var array
	 */
	private $drupal = [
		'version'       => null,
		'formats'       => [],
		'types'         => [],
		'vocabularies'  => [],
		'comment_types' => [],
	];

	/**
	 * Unified validation flags set during XML analysis.
	 * Eliminates redundant checks throughout the script.
	 * @var array
	 */
	private $requirements = [
		'needs_media'    => false,
		'needs_comments' => false,
		'needs_taxonomy' => false,
		'needs_users'    => false,
	];

	/**
	 * User-defined configuration settings for the import process.
	 * @var array
	 */
	private $config = [
		'format'         => null,
		'comment_format' => null,
		'post_type'      => null,
		'page_type'      => null,
		'category_vocab' => null,
		'tag_vocab'      => null,
		'comment_type'   => null,
		'import_media'   => false,
		'user_strategy'  => 'map_admin',
		'auto_publish'   => false,
		'dry_run'        => false,
	];

	/**
	 * Runtime cache mapping WordPress IDs/Strings to Drupal Entity IDs.
	 * @var array
	 */
	private $cache = [
		'users'     => [],
		'media'     => [],
		'terms_cat' => [],
		'terms_tag' => [],
		'nodes'     => [],
		'comments'  => [],
	];

	/**
	 * Migration statistics tracker.
	 * @var array
	 */
	private $stats = [
		'users_created'     => 0,
		'users_mapped'      => 0,
		'media_imported'    => 0,
		'media_failed'      => 0,
		'media_replaced'    => 0,
		'domains_replaced'  => 0,
		'terms_created'     => 0,
		'posts_created'     => 0,
		'pages_created'     => 0,
		'comments_created'  => 0,
		'aliases_preserved' => 0,
	];

	// Console color constants for output formatting.
	const C_RESET  = "\033[0m";
	const C_RED    = "\033[31m";
	const C_GREEN  = "\033[32m";
	const C_YELLOW = "\033[33m";
	const C_CYAN   = "\033[36m";
	const C_WHITE  = "\033[37m";
	const C_BOLD   = "\033[1m";

	// ==================================================
	// CONSTRUCTOR & MAIN FLOW
	// ==================================================

	/**
	 * Class constructor.
	 *
	 * Initializes the migration logger, sets up the log file path,
	 * and prepares the internal state for the migration process.
	 */
	public function __construct() {
		@ini_set('memory_limit', '512M');
		@set_time_limit(0);
		if (!gc_enabled()) gc_enable();

		while (ob_get_level()) ob_end_flush();
		ob_implicit_flush(1);

		$dir = __DIR__ . '/_wp2dr_data';
		if (!is_dir($dir)) mkdir($dir, 0755, true);

		$this->cacheFile = $dir . '/cache.json';
		$this->logFile = $dir . '/current.log';

		if (file_exists($this->logFile)) {
			$archiveDir = $dir . '/_archive';
			if (!is_dir($archiveDir)) mkdir($archiveDir, 0755, true);
			rename($this->logFile, $archiveDir . '/log_' . date('Ymd_His') . '.log');
		}

		$this->log("=== Migration Started ===");
		$this->log("Version: 2.0 - " . date('Y-m-d H:i:s'));
	}

	/**
	 * Main entry point for the migration script.
	 *
	 * Orchestrates the entire workflow: system validation, XML scanning,
	 * configuration wizard, user confirmation, execution, and final statistics.
	 * Encapsulates the process in a global try-catch block to handle fatal errors gracefully.
	 *
	 * @return void
	 */
	public function run(): void {

		// Discovery phase
		$this->checkDrupal();
		$this->analyzeXML();
		$this->scanEnvironment();
		$this->validateSystem();

		// Configuration phase
		$this->loadCache();
		$this->configure();
		$this->confirmImport();

		// Execution phase
		$this->executeImport();

		// Finalization
		if (!$this->config['dry_run']) {
			$this->saveCache();
		}
		$this->showStats();
	}

	// ==================================================
	// PHASE 1: DISCOVERY
	// ==================================================

	/**
	 * Verifies that the script is running within a bootstrapped Drupal environment.
	 *
	 * Ensures that the \Drupal class exists and the service container is available.
	 * Aborts execution with an error message if Drupal is not detected
	 * (e.g., if the script is run directly via php instead of drush).
	 *
	 * @return void
	 */
	private function checkDrupal() {
		$this->header("WP > Drupal: System Check");

		$version = \Drupal::VERSION;
		if (!preg_match('/^11\./', $version)) {
			throw new \Exception("Requires Drupal 11.x (found: $version)");
		}

		$this->success("✓ Drupal $version");
		$this->log("Drupal version: $version");
	}

	/**
	 * Pre-analyzes the selected WordPress XML file structure.
	 *
	 * Parses the file efficiently to extract metadata needed for the configuration wizard:
	 * - Counts total items.
	 * - Identifies unique authors.
	 * - Lists available post types (post, page, attachment, etc.).
	 * - Detects available taxonomies (categories, tags).
	 *
	 * This step ensures the user only configures options relevant to the actual data.
	 *
	 * @throws \Exception If the XML file is malformed or unreadable.
	 * @return void
	 */
	private function analyzeXML() {
		$files = glob(__DIR__ . '/*.xml');
		if (empty($files)) {
			throw new \Exception("No XML files found");
		}

		if (count($files) === 1) {
			$this->xmlFile = $files[0];
			$this->info("\n✓ Found: " . basename($this->xmlFile));
		} else {
			$this->info("\nAvailable XML files:");
			foreach ($files as $i => $file) {
				echo "  [" . ($i + 1) . "] " . basename($file) . "\n";
			}
			$choice = $this->prompt("Select file", 1, range(1, count($files)));
			$this->xmlFile = $files[$choice - 1];
		}

		$this->log("XML selected: {$this->xmlFile}");

		$reader = new \XMLReader();
		if (!$reader->open($this->xmlFile)) {
			throw new \Exception("Cannot open XML file");
		}

		try {
			while ($reader->read()) {
				if ($reader->nodeType !== \XMLReader::ELEMENT) continue;

				// Detect Base URL
				if ($reader->name === 'link' && !isset($this->xml['base_url'])) {
					$url = $reader->readString();
					if (filter_var($url, FILTER_VALIDATE_URL)) {
						$this->xml['base_url'] = rtrim($url, '/');
						$this->xml['domain'] = parse_url($url, PHP_URL_HOST);
					}
				}

				// Detect Authors
				if ($reader->name === 'wp:author') {
					$node = new \SimpleXMLElement($reader->readOuterXml());
					$ns = $node->getNamespaces(true);
					$wp = $node->children($ns['wp'] ?? 'http://wordpress.org/export/1.2/');
					$email = (string)$wp->author_email;
					if ($email) {
						$this->xml['authors'][$email] = [
							'login'   => (string)$wp->author_login ?: explode('@', $email)[0],
							'display' => (string)$wp->author_display_name ?: $email,
						];
					}
				}

				// Analyze Items
				if ($reader->name === 'item') {
					$node = new \SimpleXMLElement($reader->readOuterXml());
					$ns = $node->getNamespaces(true);
					$wp = $node->children($ns['wp'] ?? 'http://wordpress.org/export/1.2/');
					$type = (string)$wp->post_type;
					$status = (string)$wp->status;

					if ($status !== 'publish' && $status !== 'inherit') continue;

					if ($type === 'post') {
						$this->xml['posts']++;
						foreach ($node->category as $cat) {
							$domain = (string)$cat['domain'];
							$name = (string)$cat;
							if ($domain === 'category' && !in_array($name, $this->xml['categories'])) {
								$this->xml['categories'][] = $name;
							}
							if ($domain === 'post_tag' && !in_array($name, $this->xml['tags'])) {
								$this->xml['tags'][] = $name;
							}
						}
						if (isset($wp->comment)) {
							foreach ($wp->comment as $c) {
								if ((string)$c->comment_approved === '1') {
									$this->xml['comments']++;
								}
							}
						}
					} elseif ($type === 'page') {
						$this->xml['pages']++;
					} elseif ($type === 'attachment') {
						$post_id = (string)$wp->post_id;
						$url = (string)$wp->attachment_url;
						if ($post_id && $url) {
							// Extract alt text from postmeta
							$alt = null;
							if (isset($wp->postmeta)) {
								foreach ($wp->postmeta as $meta) {
									if ((string)$meta->meta_key === '_wp_attachment_image_alt') {
										$alt = (string)$meta->meta_value;
										break;
									}
								}
							}

							$this->xml['attachments'][$post_id] = [
								'url' => $url,
								'alt' => $alt,
								'title' => (string)$node->title
							];
						}
					}
				}
			}
		} finally {
			@$reader->close();
		}

		// Set requirements flags
		$this->requirements['needs_users']    = count($this->xml['authors']) > 0;
		$this->requirements['needs_media']    = count($this->xml['attachments']) > 0;
		$this->requirements['needs_comments'] = $this->xml['comments'] > 0;
		$this->requirements['needs_taxonomy'] = (count($this->xml['categories']) + count($this->xml['tags'])) > 0;

		echo "\n===== XML Content =====\n";
		$this->warn('[' . ($this->xml['domain'] ?: 'unknown') . ']');
		$stats = [
			'Authors'    => count($this->xml['authors']),
			'Posts'      => $this->xml['posts'],
			'Pages'      => $this->xml['pages'],
			'Media'      => count($this->xml['attachments']),
			'Categories' => count($this->xml['categories']),
			'Tags'       => count($this->xml['tags']),
			'Comments'   => $this->xml['comments'],
		];

		foreach ($stats as $label => $value) {
			printf("%-12s %6d\n", $label . ':', $value);
		}

		$this->log("XML analyzed: {$this->xml['posts']} posts, {$this->xml['pages']} pages");
	}

	/**
	 * Scans the current working directory for WordPress XML export files.
	 *
	 * Automatically detects files with the .xml extension.
	 * - If one file is found, it is selected automatically.
	 * - If multiple files are found, the user is prompted to choose one.
	 * - If no files are found, the user is prompted to enter a path manually.
	 *
	 * @return void
	 */
	private function scanEnvironment() {
		$this->header("Environment Scan");

		$types = \Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple();
		foreach ($types as $id => $type) {
			$this->drupal['types'][$id] = [
				'label' => $type->label(),
				'fields' => $this->detectFields($id),
			];
		}

		$vocabs = \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->loadMultiple();
		foreach ($vocabs as $id => $vocab) {
			$this->drupal['vocabularies'][$id] = $vocab->label();
		}

		$formats = filter_formats();
		foreach ($formats as $id => $format) {
			$this->drupal['formats'][$id] = $format->label();
		}

		if (\Drupal::moduleHandler()->moduleExists('comment')) {
			$types = \Drupal::entityTypeManager()->getStorage('comment_type')->loadMultiple();
			foreach ($types as $id => $type) {
				$this->drupal['comment_types'][$id] = $type->label();
			}
		}

		$this->success("✓ Environment scanned");
	}

	/**
	 * Performs system-level compatibility checks.
	 *
	 * Verifies that the PHP environment meets the requirements:
	 * - Required extensions (SimpleXML, DOM, JSON, libxml).
	 * - Sufficient memory limit (suggests increasing if low).
	 * - Valid max_execution_time settings.
	 *
	 * @throws \Exception If a critical system requirement is missing.
	 * @return void
	 */
	private function validateSystem() {
		$this->header("Validation");

		$errors = [];
		$warnings = [];

		// 1. TEXT FORMATS (always required)
		if (empty($this->drupal['formats'])) {
			$errors[] = "✗ No text formats found";
		}

		// 2. CONTENT TYPES (required if posts/pages exist)
		$suitable = $this->getTypesWithBody();
		if (($this->xml['posts'] + $this->xml['pages']) > 0 && empty($suitable)) {
			$errors[] = "✗ No content types with body field";
		} elseif (!empty($suitable)) {
			$this->success("✓ " . count($suitable) . " content types available");
		}

		// 3. MEDIA MODULE (only if needed)
		if ($this->requirements['needs_media']) {
			while (true) {
				$module_enabled = \Drupal::moduleHandler()->moduleExists('media');

				if ($module_enabled) {
					$this->success("✓ Media module enabled");
					break;
				}

				// Module not enabled
				echo "\n";
				$this->warn("✗ Media module not enabled (" . count($this->xml['attachments']) . " attachments found)");
				echo "\n" . self::C_CYAN . "Why Media module?" . self::C_RESET . "\n";
				echo "  • Modern standard for file management in Drupal\n";
				echo "  • Handles images, documents, video, audio\n";
				echo "  • Better media library and reusability\n";
				echo "  • Included by default in Drupal CMS\n\n";
				echo self::C_YELLOW . "Quick install: " . self::C_BOLD . "drush en media -y" . self::C_RESET . "\n\n";

				$choice = $this->prompt(
					"Choose:\n" .
					"  1) Try again (I've enabled it)\n" .
					"  2) Skip media import (not recommended)\n" .
					"  3) Exit script",
					'1',
					['1', '2', '3']
				);

				if ($choice === 1) {
					continue; // Check again
				} elseif ($choice === 2) {
					$warnings[] = "⚠ Media import will be skipped - featured images won't work";
					$this->requirements['needs_media'] = false;
					break;
				} elseif ($choice === 3) {
					$errors[] = "✗ Media module required but not enabled";
					break;
				}
			}
		}

		// 4. COMMENT MODULE (only if needed)
		if ($this->requirements['needs_comments']) {
			while (true) {
				$module_enabled = \Drupal::moduleHandler()->moduleExists('comment');

				if ($module_enabled) {
					// Rescan comment types
					$types = \Drupal::entityTypeManager()->getStorage('comment_type')->loadMultiple();
					foreach ($types as $id => $type) {
						$this->drupal['comment_types'][$id] = $type->label();
					}

					if (!empty($this->drupal['comment_types'])) {
						$this->success("✓ Comment module enabled with comment types");
						break;
					} else {
						// Module enabled but no types
						$this->warn("✗ Comment module enabled but no comment types found");
						$this->warn("  Configure at: /admin/structure/comment");

						$choice = $this->prompt(
							"Choose:\n" .
							"  1) Try again (I've added a comment type)\n" .
							"  2) Skip comments import\n" .
							"  3) Exit script",
							'1',
							['1', '2', '3']
						);

						if ($choice === 1) {
							continue; // Check again
						} elseif ($choice === 2) {
							$warnings[] = "⚠ Comments will be skipped";
							$this->requirements['needs_comments'] = false;
							break;
						} elseif ($choice === 3) {
							$errors[] = "✗ No comment types configured";
							break;
						}
					}
				} else {
					// Module not enabled
					$this->warn("✗ Comment module not enabled ({$this->xml['comments']} comments)");
					$this->warn("  Enable it with: drush en comment -y");

					$choice = $this->prompt(
						"Choose:\n" .
						"  1) Try again (I've enabled it)\n" .
						"  2) Skip comments import\n" .
						"  3) Exit script",
						'1',
						['1', '2', '3']
					);

					if ($choice === 1) {
						continue; // Check again
					} elseif ($choice === 2) {
						$warnings[] = "⚠ Comments will be skipped";
						$this->requirements['needs_comments'] = false;
						break;
					} elseif ($choice === 3) {
						$errors[] = "✗ Comment module required but not enabled";
						break;
					}
				}
			}
		}

		// 5. VOCABULARIES (only if needed)
		if ($this->requirements['needs_taxonomy']) {
			if (empty($this->drupal['vocabularies'])) {
				$warnings[] = "⚠ No vocabularies (taxonomy will be skipped)";
				$this->requirements['needs_taxonomy'] = false;
			} else {
				$this->success("✓ " . count($this->drupal['vocabularies']) . " vocabularies");
			}
		}

		// Output
		if (!empty($warnings)) {
			echo "\n";
			foreach ($warnings as $w) $this->warn($w);
		}

		if (!empty($errors)) {
			echo "\n";
			foreach ($errors as $e) $this->error($e);
			throw new \Exception("Validation failed");
		}

		$this->success("\n✓ System ready");
	}

	// ==================================================
	// PHASE 2: CONFIGURATION
	// ==================================================

	/**
	 * Loads the migration cache from a JSON file.
	 *
	 * Retrieves mapping data (users, media, terms, nodes) from previous runs
	 * to prevent duplicates and allow resuming interrupted migrations.
	 *
	 * @return void
	 */
	private function loadCache() {
		if (!file_exists($this->cacheFile)) return;

		$this->warn("\nCache found: " . basename($this->cacheFile));
		echo "  [1] Use cache (Resume from where you left off)\n";
		echo "  [2] Start fresh (Archive old cache and restart)\n\n";
		$choice = $this->prompt("Action", '1', ['1', '2']);

		if ($choice == 2) {
			$dir = __DIR__ . '/_wp2dr_data/_archive';
			if (!is_dir($dir)) mkdir($dir, 0755, true);
			rename($this->cacheFile, $dir . '/cache_' . date('Ymd_His') . '.json');
			$this->success("✓ Cache archived");
			return;
		}

		$data = json_decode(file_get_contents($this->cacheFile), true);
		if ($data) {
			$this->cache = array_merge($this->cache, $data);
			$this->success("✓ Cache loaded");
		}
	}

	/**
	 * Interactive wizard to configure migration settings.
	 *
	 * Prompts the user to select text formats, content types, vocabularies,
	 * and handling strategies for users and media via CLI input.
	 *
	 * @return void
	 */
	private function configure() {
		$this->header("Configuration");

		// Text format for content
		$default = isset($this->drupal['formats']['full_html']) ? 'full_html' : array_key_first($this->drupal['formats']);
		$this->config['format'] = $this->choose("\n--- Text Format (Content) ---", $this->drupal['formats'], $default);

		// Text format for comments (if needed)
		if ($this->requirements['needs_comments'] && !empty($this->drupal['comment_types'])) {
			$default = isset($this->drupal['formats']['plain_text']) ? 'plain_text' : array_key_first($this->drupal['formats']);
			$this->config['comment_format'] = $this->choose("\n--- Text Format (Comments) ---", $this->drupal['formats'], $default);
		}

		// Posts
		if ($this->xml['posts'] > 0) {
			$types = $this->getTypesWithBody();
			if (!empty($types)) {
				$this->config['post_type'] = $this->choose("\n--- Posts ({$this->xml['posts']}) ---", $types, null, true);
			}
		}

		// Pages
		if ($this->xml['pages'] > 0) {
			$types = $this->getTypesWithBody();
			if (!empty($types)) {
				$this->config['page_type'] = $this->choose("\n--- Pages ({$this->xml['pages']}) ---", $types, null, true);
			}
		}

		// Taxonomy (only if needed and available)
		if ($this->requirements['needs_taxonomy'] && !empty($this->drupal['vocabularies'])) {
			if (count($this->xml['categories']) > 0) {
				$this->config['category_vocab'] = $this->choose("\n--- Categories (" . count($this->xml['categories']) . ") ---", $this->drupal['vocabularies'], null, true);
			}
			if (count($this->xml['tags']) > 0) {
				$this->config['tag_vocab'] = $this->choose("\n--- Tags (" . count($this->xml['tags']) . ") ---", $this->drupal['vocabularies'], null, true);
			}
		}

		// Comments (only if needed and available)
		if ($this->requirements['needs_comments'] && !empty($this->drupal['comment_types'])) {
			$this->config['comment_type'] = $this->choose("\n--- Comments ({$this->xml['comments']}) ---", $this->drupal['comment_types'], null, true);
		}

		// Media (only if needed)
		if ($this->requirements['needs_media']) {
			$this->config['import_media'] = $this->prompt("\n--- Media (" . count($this->xml['attachments']) . ") ---\nDo you wanto to Import Attachment? (y/n)", 'y', ['y', 'n']);
		}

		// Users
		if ($this->requirements['needs_users']) {
			echo "\n--- Users (" . count($this->xml['authors']) . ") ---\n";
			foreach ($this->xml['authors'] as $email => $data) {
				echo "  • {$data['display']} <$email>\n";
			}

			echo "\nHow should authors be handled if they do NOT already exist?\n";
			echo "  [1] Map missing authors to admin user (default)\n";
			echo "  [2] Create missing Drupal users\n";
			$choice = $this->prompt("Choice", '1', ['1', '2']);
			$this->config['user_strategy'] = ($choice == 1) ? 'map_admin' : 'create_users';
		}

	}

	/**
	 * Displays a summary of the configuration and asks for confirmation.
	 *
	 * Allows the user to choose between a "Dry Run" (simulation) and
	 * a "Live Import". Handles the cancellation of the script.
	 *
	 * @throws \Drush\Exceptions\UserAbortException If the user cancels the operation.
	 * @return void
	 */
	private function confirmImport() {
		$this->header("Summary");

		echo "\n" . self::C_BOLD . "Configuration:" . self::C_RESET . "\n";
		echo "Content Format: {$this->config['format']}\n";
		if ($this->config['comment_format']) {
			echo "Comment Format: {$this->config['comment_format']}\n";
		}
		if ($this->config['post_type']) {
			echo "Posts:          {$this->config['post_type']}\n";
		}
		if ($this->config['page_type']) {
			echo "Pages:          {$this->config['page_type']}\n";
		}
		if ($this->config['category_vocab']) {
			echo "Categories:     {$this->config['category_vocab']}\n";
		}
		if ($this->config['tag_vocab']) {
			echo "Tags:           {$this->config['tag_vocab']}\n";
		}
		if ($this->config['comment_type']) {
			echo "Comments:       {$this->config['comment_type']}\n";
		}
		$import_media = !empty($this->config['import_media']) ? 'YES' : 'No';
		$user_summary = $this->config['user_strategy'] === 'map_admin' ? 'Map to admin' : 'Create';
		echo "Import Media:   $import_media\n";
		echo "Users Strategy: $user_summary\n\n";
		echo "[y] Live import " . self::C_RED . "(changes database!)" . self::C_RESET . "\n";
		echo "[d] Dry run " . self::C_GREEN . "(test mode)" . self::C_RESET . "\n";
		echo "[n] Cancel\n\n";
		$choice = $this->prompt("Choose", 'd', ['y', 'd', 'n']);
		if ($choice === 'n') {
			throw new UserAbortException("Cancelled");
		}

		$this->config['dry_run'] = ($choice === 'd');
		if (!$this->config['dry_run']) {
			$this->config['auto_publish'] = $this->prompt("\nAuto-publish content? (y/n)", 'y', ['y', 'n']);
			$this->warn("\n*** LIVE IMPORT - 3 seconds to cancel ***");
			sleep(3);
		} else {
			$this->success("\n*** DRY RUN MODE ***");
		}
	}

	// ==================================================
	// PHASE 3: EXECUTION
	// ==================================================

	/**
	 * Orchestrates the execution of the import process.
	 *
	 * Runs the individual import methods in the correct dependency order:
	 * Users -> Media -> Taxonomy -> Content -> Comments.
	 *
	 * @return void
	 */
	private function executeImport() {
		$this->log("=== Import Started ===");

		if ($this->requirements['needs_users']) $this->importUsers();
		if ($this->config['import_media']) $this->importMedia();
		if ($this->requirements['needs_taxonomy']) $this->importTaxonomy();

		$this->importContent();

		if ($this->config['comment_type']) $this->importComments();

		$this->log("=== Import Completed ===");
	}

	/**
	 * Imports WordPress authors as Drupal users.
	 *
	 * Depending on configuration, either maps all authors to the admin account
	 * or creates new User entities, handling duplicate username collisions.
	 *
	 * @return void
	 */
	private function importUsers() {
		$this->header("Users");

		if ($this->config['user_strategy'] === 'map_admin') {
			foreach ($this->xml['authors'] as $email => $data) {
				$this->cache['users'][$email] = 1;
				$this->stats['users_mapped']++;
			}
			$this->success("✓ All authors → admin");
			return;
		}

		$counter = [];
		foreach ($this->xml['authors'] as $email => $data) {
			$existing = \Drupal::entityTypeManager()->getStorage('user')
				->loadByProperties(['mail' => $email]);

			if (!empty($existing)) {
				$this->cache['users'][$email] = reset($existing)->id();
				$this->stats['users_mapped']++;
				continue;
			}

			$username = $data['login'];
			$by_name = \Drupal::entityTypeManager()->getStorage('user')
				->loadByProperties(['name' => $username]);

			if (!empty($by_name)) {
				$counter[$username] = ($counter[$username] ?? 1) + 1;
				$username .= '_' . $counter[$username];
			}

			if ($this->config['dry_run']) {
				$this->cache['users'][$email] = 999;
				$this->stats['users_created']++;
				continue;
			}

			try {
				$user = User::create(['name' => $username, 'mail' => $email, 'status' => 1]);
				$user->save();
				$this->cache['users'][$email] = $user->id();
				$this->stats['users_created']++;
			} catch (\Exception $e) {
				$this->cache['users'][$email] = 1;
				$this->log("ERROR: User $email failed");
			}
		}

		$this->success("✓ {$this->stats['users_created']} created, {$this->stats['users_mapped']} mapped");
	}

	/**
	 * Imports WordPress categories and tags as Drupal Taxonomy Terms.
	 *
	 * Populates the vocabularies selected during configuration.
	 * Updates the internal cache with term mappings.
	 *
	 * @return void
	 */
	private function importTaxonomy() {
		$this->header("Taxonomy");

		if ($this->config['category_vocab']) {
			foreach ($this->xml['categories'] as $name) {
				$tid = $this->createTerm($name, $this->config['category_vocab']);
				if ($tid) {
					$this->cache['terms_cat'][$name] = $tid;
					$this->stats['terms_created']++;
				}
			}
		}

		if ($this->config['tag_vocab']) {
			foreach ($this->xml['tags'] as $name) {
				$tid = $this->createTerm($name, $this->config['tag_vocab']);
				if ($tid) {
					$this->cache['terms_tag'][$name] = $tid;
					$this->stats['terms_created']++;
				}
			}
		}

		$this->success("✓ {$this->stats['terms_created']} terms created");
	}

	/**
	 * Downloads and creates Media entities from WordPress attachments.
	 *
	 * Iterates through attachment posts in the XML, downloads the files locally,
	 * registers File entities, and creates the corresponding Media entities (Image, Document, etc.).
	 *
	 * @return void
	 */
	private function importMedia() {
		$this->header("Media");

		$dir = 'public://wp-imported';
		\Drupal::service('file_system')->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);

		$count = 0;
		$total = count($this->xml['attachments']);

		foreach ($this->xml['attachments'] as $post_id => $data) {
			$count++;
			$this->progress($count, $total);

			$url = $data['url'];
			// Use alt if available, fallback to title
			$alt = $data['alt'] ?: ($data['title'] ?: pathinfo(basename($url), PATHINFO_FILENAME));

			if (parse_url($url, PHP_URL_HOST) !== $this->xml['domain']) continue;

			if ($this->config['dry_run']) {
				$this->cache['media'][$url] = ['url' => '/dummy.jpg', 'alt' => $alt];
				$this->stats['media_imported']++;
				continue;
			}

			$media = $this->createMedia($url, $dir, $alt);
			if ($media) {
				$this->cache['media'][$url] = [
					'media_id' => $media->id(),
					'url' => $this->getMediaUrl($media),
					'alt' => $alt
				];
				$this->stats['media_imported']++;
			} else {
				$this->stats['media_failed']++;
			}

			if ($count % 50 === 0) $this->clearMemory('media');
		}

		echo "\n";
		$this->success("✓ {$this->stats['media_imported']} imported, {$this->stats['media_failed']} failed");
	}

	/**
	 * Parses WordPress posts/pages and creates Drupal Nodes.
	 *
	 * Reads the XML again to process 'item' nodes. handles content cleaning,
	 * author mapping, date conversion, and field population.
	 *
	 * @throws \Exception If the XML file cannot be reopened.
	 * @return void
	 */
	private function importContent() {
		$this->header("Content");

		$reader = new \XMLReader();
		if (!$reader->open($this->xmlFile)) throw new \Exception("Cannot reopen XML");

		$count = 0;
		$total = $this->xml['posts'] + $this->xml['pages'];

		try {
			while ($reader->read()) {
				if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'item') continue;

				$item = new \SimpleXMLElement($reader->readOuterXml());
				$ns = $item->getNamespaces(true);
				$wp = $item->children($ns['wp'] ?? 'http://wordpress.org/export/1.2/');

				$type = (string)$wp->post_type;
				$status = (string)$wp->status;

				if ($status !== 'publish' || !in_array($type, ['post', 'page'])) continue;

				$bundle = ($type === 'post') ? $this->config['post_type'] : $this->config['page_type'];
				if (!$bundle) continue;

				$count++;
				$this->progress($count, $total);

				$result = $this->createNode($item, $type, $bundle, $ns);
				if ($result === 'created') {
					$type === 'post' ? $this->stats['posts_created']++ : $this->stats['pages_created']++;
				}

				if ($count % 50 === 0) $this->clearMemory('node');
			}
		} finally {
			@$reader->close();
		}

		echo "\n";
		$this->success("✓ {$this->stats['posts_created']} posts, {$this->stats['pages_created']} pages");
	}

	/**
	 * Imports comments and attaches them to the migrated nodes.
	 *
	 * Reconstructs the comment threading (parent/child relationships) and
	 * maps the comment author to the correct Drupal user (or anonymous).
	 *
	 * @throws \Exception If the XML file cannot be reopened.
	 * @return void
	 */
	private function importComments() {
		$this->header("Comments");

		$field = $this->findCommentField($this->config['post_type']);
		if (!$field) {
			$this->warn("No comment field found");
			return;
		}

		$reader = new \XMLReader();
		if (!$reader->open($this->xmlFile)) throw new \Exception("Cannot reopen XML");

		$map = [];
		try {
			while ($reader->read()) {
				if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'item') continue;

				$item = new \SimpleXMLElement($reader->readOuterXml());
				$ns = $item->getNamespaces(true);
				$wp = $item->children($ns['wp'] ?? 'http://wordpress.org/export/1.2/');

				if ((string)$wp->post_type !== 'post' || !isset($wp->comment)) continue;

				$nid = $this->cache['nodes'][(string)$wp->post_id] ?? null;
				if (!$nid) continue;

				foreach ($wp->comment as $c) {
					$cid = $this->createComment($c, $nid, $field, $map);
					if ($cid) $this->stats['comments_created']++;
				}
			}
		} finally {
			@$reader->close();
		}

		$this->success("✓ {$this->stats['comments_created']} comments");
	}

	// ==================================================
	// HELPER METHODS
	// ==================================================

	/**
	 * Retrieves all field definitions for a specific content type.
	 *
	 * Used to identify available fields (like images, taxonomy references)
	 * in the target bundle to ensure data is mapped correctly.
	 *
	 * @param string $bundle The machine name of the content type.
	 *
	 * @return \Drupal\Core\Field\FieldDefinitionInterface[] An array of field definitions.
	 */
	private function detectFields($bundle) {
		$defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $bundle);
		$fields = ['body' => null, 'excerpt' => null, 'image' => null];

		// 1. Detect Body field
		foreach (['field_content', 'body', 'field_body'] as $name) {
			if (isset($defs[$name]) && in_array($defs[$name]->getType(), ['text_with_summary', 'text_long'])) {
				$fields['body'] = $name;
				break;
			}
		}

		// 2. Detect Excerpt field
		foreach (['field_description', 'field_excerpt', 'field_summary'] as $name) {
			if (isset($defs[$name])) {
				$fields['excerpt'] = $name;
				break;
			}
		}

		// 3. Detect Image field
		foreach (['field_featured_image', 'field_image', 'field_media_image'] as $name) {
			if (isset($defs[$name])) {
				$fields['image'] = $name;
				break;
			}
		}

		// 4. Detect Comment field
		foreach ($defs as $name => $def) {
			if ($def->getType() === 'comment') {
				$fields['comment'] = $name;
				break;
			}
		}

		return $fields;
	}

	/**
	 * Identifies all content types that have a 'body' field.
	 *
	 * Used during the configuration wizard to suggest valid target
	 * content types for importing WordPress posts/pages.
	 *
	 * @return array An associative array of bundle IDs and their labels (e.g., ['article' => 'Article']).
	 */
	private function getTypesWithBody() {
		$result = [];
		foreach ($this->drupal['types'] as $id => $data) {
			if ($data['fields']['body']) {
				$result[$id] = $data['label'];
			}
		}
		return $result;
	}

	/**
	 * Finds the machine name of the comment field for a given bundle.
	 *
	 * Scans the fields of a content type to find one of type 'comment'.
	 *
	 * @param string $bundle The content type machine name.
	 *
	 * @return string|null The field machine name (e.g., 'comment') or null if not found.
	 */
	private function findCommentField($bundle) {
		return $this->drupal['types'][$bundle]['fields']['comment'] ?? null;
	}

	/**
	 * Creates or retrieves a Taxonomy Term by name.
	 *
	 * @param string $name  The name of the term.
	 * @param string $vocab The vocabulary ID (machine name).
	 *
	 * @return int|null The Term ID (tid) or null on failure.
	 */
	private function createTerm($name, $vocab) {
		$name = trim($name);
		if (!$name) return null;

		$existing = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
			->loadByProperties(['name' => $name, 'vid' => $vocab]);

		if (!empty($existing)) return reset($existing)->id();
		if ($this->config['dry_run']) return 999;

		try {
			$term = Term::create(['vid' => $vocab, 'name' => $name]);
			$term->save();
			return $term->id();
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Downloads a remote file and creates a Drupal Media entity.
	 *
	 * Handles duplicate checks, file saving, MIME type detection, and
	 * media bundle selection (Image, Video, Audio, Document).
	 *
	 * @param string $url The remote URL of the file.
	 * @param string $dir The destination directory (stream wrapper).
	 * @param string $alt The alternative text for the media.
	 *
	 * @return \Drupal\media\Entity\Media|null The created Media entity or null on failure.
	 */
	private function createMedia($url, $dir, $alt) {
		try {
			$filename = basename(parse_url($url, PHP_URL_PATH));
			$uri = $dir . '/' . $filename;

			// Check if file already exists
			$path = \Drupal::service('file_system')->realpath($uri);
			if ($path && file_exists($path)) {
				$files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
				$file = !empty($files) ? reset($files) : null;
			} else {
				$file = null;
			}

			// Download if missing
			if (!$file) {
				$client = \Drupal::httpClient();
				$response = $client->get($url, ['timeout' => 30, 'verify' => false]);
				$data = (string)$response->getBody();
				if (!$data) return null;

				$file = \Drupal::service('file.repository')->writeData($data, $uri, FileSystemInterface::EXISTS_REPLACE);
				if ($file) {
					// FIX: Set proper MIME type
					$mime = \Drupal::service('file.mime_type.guesser')->guessMimeType($uri);
					$file->setMimeType($mime);
					$file->setOwnerId(1);
					$file->setPermanent();
					$file->save();
				}
			}

			if (!$file) return null;

			// Determine Media Bundle
			$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
			$config = match ($ext) {
				// Documents + Archives
				'pdf', 'doc', 'docx', 'odt', 'txt', 'rtf',
				'zip', 'rar', 'tar', 'gz', '7z', 'bz2', 'tgz' => ['bundle' => 'document', 'field' => 'field_media_document'],
				// Video
				'mp4', 'mov', 'avi', 'wmv', 'flv', 'webm', 'mkv' => ['bundle' => 'video', 'field' => 'field_media_video_file'],
				// Audio
				'mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a' => ['bundle' => 'audio', 'field' => 'field_media_audio_file'],
				// Images
				default => ['bundle' => 'image', 'field' => 'field_media_image'],
			};

			// Avoid duplicates
			$existing = \Drupal::entityTypeManager()->getStorage('media')
				->loadByProperties([$config['field'] . '.target_id' => $file->id()]);

			if (!empty($existing)) return reset($existing);

			$data = [
				'bundle'		=> $config['bundle'],
				'name'		  => $alt,
				$config['field'] => ['target_id' => $file->id()],
				'uid'		   => 1,
				'status'		=> 1,
			];

			if ($config['bundle'] === 'image') {
				$data[$config['field']]['alt'] = $alt;
			}

			$media = Media::create($data);
			$media->save();
			return $media;

		} catch (\Exception $e) {
			$this->log("Media error: $url - " . $e->getMessage());
			return null;
		}
	}

	/**
	 * Retrieves the absolute URL of the file associated with a Media entity.
	 *
	 * @param \Drupal\media\Entity\Media $media The media entity.
	 *
	 * @return string|null The public URL of the file or null if not found.
	 */
	private function getMediaUrl($media) {
		try {
			$field = $media->getSource()->getConfiguration()['source_field'] ?? null;
			if (!$field || !$media->hasField($field)) return null;
			$file = $media->get($field)->entity;
			return $file ? \Drupal::service('file_url_generator')->generateString($file->getFileUri()) : null;
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Creates a single Drupal Node from a WordPress XML item.
	 *
	 * Handles body content cleaning, summary generation, path alias preservation,
	 * and association with taxonomy terms and featured images.
	 *
	 * @param \SimpleXMLElement $item   The XML item element.
	 * @param string            $type   The WordPress post type (post/page).
	 * @param string            $bundle The target Drupal content type machine name.
	 * @param array             $ns     The XML namespaces.
	 *
	 * @return string 'created', 'skipped', or 'error'.
	 */
	private function createNode($item, $type, $bundle, $ns) {
		$wp = $item->children($ns['wp']);
		$dc = $item->children($ns['dc'] ?? 'http://purl.org/dc/elements/1.1/');
		$content_ns = $item->children($ns['content'] ?? 'http://purl.org/rss/1.0/modules/content/');

		$wp_id = (string)$wp->post_id;
		$title = (string)$item->title;
		$created = strtotime((string)$wp->post_date) ?: time();

		// Check for duplicates
		if (!$this->config['dry_run']) {
			$existing = \Drupal::entityQuery('node')
				->condition('type', $bundle)
				->condition('title', $title)
				->condition('created', $created)
				->accessCheck(false)
				->range(0, 1)
				->execute();
			if (!empty($existing)) return 'skipped';
		}

		$fields = $this->drupal['types'][$bundle]['fields'];
		if (!$fields['body']) return 'error';

		$uid = $this->cache['users'][(string)$dc->creator] ?? 1;
		$alias = $this->extractAlias((string)$item->link);
		$content = $this->cleanContent((string)$content_ns->encoded);
		$excerpt = (string)$item->description ?: $this->generateExcerpt($content);

		$node_data = [
			'type'    => $bundle,
			'title'   => $title,
			'uid'     => $uid,
			'created' => $created,
			'changed' => strtotime((string)$wp->post_modified) ?: $created,
			$fields['body'] => ['value' => $content, 'format' => $this->config['format']],
			'status'  => $this->config['auto_publish'] ? 1 : 0,
		];

		if (\Drupal::moduleHandler()->moduleExists('content_moderation')) {
			$node_data['moderation_state'] = $this->config['auto_publish'] ? 'published' : 'draft';
		}

		if ($fields['excerpt']) {
			$node_data[$fields['excerpt']] = ['value' => $excerpt];
		}

		if ($type === 'post') {
			$this->addTaxonomy($item, $node_data, $bundle);
			$this->addFeaturedImage($wp, $node_data, $fields['image']);
		}

		if ($this->config['dry_run']) {
			$this->cache['nodes'][$wp_id] = 999;
			return 'created';
		}

		try {
			$node = Node::create($node_data);
			if ($alias) {
				$node->set('path', ['alias' => $alias, 'pathauto' => 0]);
				$this->stats['aliases_preserved']++;
			}
			$node->save();
			$this->cache['nodes'][$wp_id] = $node->id();
			return 'created';
		} catch (\Exception $e) {
			$this->log("Node error: $title - " . $e->getMessage());
			return 'error';
		}
	}

	/**
	 * Extracts and validates the URL alias path from a WordPress permalink.
	 *
	 * Ensures the path is valid for Drupal and does not conflict with
	 * reserved system paths (admin, user, node).
	 *
	 * @param string $url The full WordPress permalink URL.
	 *
	 * @return string|null The relative path (e.g., '/my-blog-post') or null.
	 */
	private function extractAlias($url) {
		if (!$url) return null;
		$path = parse_url($url, PHP_URL_PATH) ?? '';
		if (!$path || $path === '/') return null;
		$path = '/' . trim($path, '/');

		if (!preg_match('#^/[a-z0-9\-_/]{1,254}$#i', $path)) return null;

		foreach (['/admin', '/user', '/node', '/taxonomy'] as $p) {
			if (str_starts_with($path, $p)) return null;
		}
		return $path;
	}

	/**
	 * Cleans and formats HTML content for Drupal.
	 *
	 * Removes Gutenberg block comments, inline classes/styles, and empty tags.
	 * Replaces old WordPress media URLs with new Drupal file URLs.
	 *
	 * @param string $content The raw HTML content from WordPress.
	 *
	 * @return string The sanitized and processed HTML.
	 */
	private function cleanContent($content) {
		if (!$content) return $content;

		// Remove Gutenberg blocks
		$content = preg_replace('/<!--\s*\/?wp:.*?-->/s', '', $content);

		// Remove classes
		$content = preg_replace('/<([a-z0-9]+)([^>]*?)\s+class=["\'][^"\']*["\']([^>]*?)>/i', '<$1$2$3>', $content);

		// Remove data-attributes recursively
		do {
			$content = preg_replace(
				'/<([a-z0-9]+)([^>]*?)\s+data-[a-z0-9\-]+=["\'][^"\']*["\']([^>]*?)>/i',
				'<$1$2$3>',
				$content,
				-1,
				$count
			);
		} while ($count > 0);

		// Clean up empty spaces in tags
		$content = preg_replace('/<([a-z0-9]+)\s+>/i', '<$1>', $content);

		// Remove empty tags (except media/embeds)
		$exclude = 'iframe|script|video|audio|object|embed';
		$pattern = '#<(?!(?:' . $exclude . ')\b)([a-z0-9]+)(?![^>]*\s+id=)(?:\s[^>]*)?>\s*(?:&nbsp;)*\s*</\1>#is';
		do {
			$content = preg_replace($pattern, '', $content, -1, $removed);
		} while ($removed > 0);

		// Replace Media URLs
		if (!empty($this->cache['media'])) {
			foreach ($this->cache['media'] as $old_url => $data) {
				$new_url = $data['url'] ?? null;
				if (!$new_url) continue;

				$old_filename = basename($old_url);
				if (!preg_match('/^(.+?)(\.[^.]+)$/', $old_filename, $m)) continue;

				$base = $m[1];
				$ext = $m[2];
				$old_path = dirname(parse_url($old_url, PHP_URL_PATH));

				// Direct replacement
				if (strpos($content, $old_url) !== false) {
					$content = str_replace($old_url, $new_url, $content);
					$this->stats['media_replaced']++;
				}

				// Regex replacement for resized versions
				$pattern = '#' . preg_quote($old_path, '#') . '/' . preg_quote($base, '#') . '-\d+x\d+' . preg_quote($ext, '#') . '#';
				$content = preg_replace($pattern, $new_url, $content, -1, $resize_count);
				if ($resize_count > 0) {
					$this->stats['media_replaced'] += $resize_count;
				}
			}
		}

		// Strip old Base URL
		if (!empty($this->xml['base_url'])) {
			$content = str_replace($this->xml['base_url'], '', $content, $count);
			if ($count > 0) {
				$this->stats['domains_replaced'] += $count;
			}
		}

		return trim($content);
	}

	/**
	 * Generates a text summary from the main content.
	 *
	 * Truncates the text to a specified length while preserving word boundaries.
	 *
	 * @param string $content The full HTML content.
	 * @param int    $max     The maximum length of the excerpt.
	 *
	 * @return string The generated excerpt.
	 */
	private function generateExcerpt($content, $max = 300) {
		$text = Html::decodeEntities(strip_tags($content));
		$text = preg_replace('/\s+/', ' ', trim($text));
		return Unicode::truncate($text, $max, TRUE, TRUE);
	}

	/**
	 * Parses and adds taxonomy terms to the node being imported.
	 *
	 * Iterates through the XML <category> tags, finds the corresponding
	 * Drupal term IDs from the cache, and appends them to the node's field.
	 *
	 * @param \Drupal\node\Entity\Node $node The node entity being built.
	 * @param string                   $type 'category' or 'post_tag'.
	 * @param \SimpleXMLElement        $item The XML item element.
	 * @param array                    $ns   The XML namespaces.
	 *
	 * @return void
	 */
	private function addTaxonomy($item, &$node_data, $bundle) {
		$defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $bundle);

		foreach ($item->category as $cat) {
			$domain = (string)$cat['domain'];
			$name = trim((string)$cat);

			$vocab = null;
			$cache_key = null;

			if ($domain === 'category' && $this->config['category_vocab']) {
				$vocab = $this->config['category_vocab'];
				$cache_key = 'terms_cat';
			} elseif ($domain === 'post_tag' && $this->config['tag_vocab']) {
				$vocab = $this->config['tag_vocab'];
				$cache_key = 'terms_tag';
			}

			if (!$vocab || !isset($this->cache[$cache_key][$name])) continue;

			$field = $this->findTaxonomyField($defs, $vocab);
			if ($field) {
				$node_data[$field][] = ['target_id' => $this->cache[$cache_key][$name]];
			}
		}
	}

	/**
	 * Finds an entity reference field targeting a specific vocabulary.
	 *
	 * Used to automatically map WordPress categories/tags to the correct
	 * taxonomy reference field in the Drupal node.
	 *
	 * @param string $bundle The content type machine name.
	 * @param string $vid    The vocabulary ID (machine name) to match.
	 *
	 * @return string|null The field machine name or null if no matching field is found.
	 */
	private function findTaxonomyField($defs, $vocab) {
		foreach ($defs as $name => $def) {
			if ($def->getType() === 'entity_reference' && $def->getSetting('target_type') === 'taxonomy_term') {
				$settings = $def->getSetting('handler_settings');
				$vocabs = $settings['target_bundles'] ?? [];
				if (empty($vocabs) || in_array($vocab, $vocabs)) {
					return $name;
				}
			}
		}
		return null;
	}

	/**
	 * Attaches the featured image (thumbnail) to the node.
	 *
	 * Looks up the WordPress thumbnail ID in the post meta, retrieves the
	 * corresponding Drupal Media ID from the migration cache, and sets the field.
	 * Attempts to guess the image field name (e.g., field_image, field_media_image).
	 *
	 * @param \Drupal\node\Entity\Node $node The node entity being built.
	 * @param \SimpleXMLElement        $item The XML item element.
	 * @param array                    $ns   The XML namespaces.
	 *
	 * @return void
	 */
	private function addFeaturedImage($wp, &$node_data, $image_field) {
		if (!$image_field) return;

		$thumb_id = null;
		foreach ($wp->postmeta as $meta) {
			if ((string)$meta->meta_key === '_thumbnail_id') {
				$thumb_id = (string)$meta->meta_value;
				break;
			}
		}

		if (!$thumb_id) return;

		$attachment = $this->xml['attachments'][$thumb_id] ?? null;
		if (!$attachment) return;

		$media_data = $this->cache['media'][$attachment['url']] ?? null;
		if (!$media_data) return;

		$media_id = $media_data['media_id'] ?? null;
		if (!$media_id || $this->config['dry_run']) return;

		$node_data[$image_field] = ['target_id' => $media_id];
	}

	/**
	 * Creates a Drupal comment entity from a WordPress comment.
	 *
	 * Handles author mapping (registered user vs anonymous), threading (parent comments),
	 * status (published/pending), and timestamp conversion.
	 *
	 * @param \SimpleXMLElement $item       The XML comment element.
	 * @param int               $nid        The ID of the node the comment belongs to.
	 * @param string            $field_name The machine name of the comment field on the node.
	 * @param array             $ns         The XML namespaces.
	 *
	 * @return void
	 */
	private function createComment($c, $nid, $field, &$map) {
		$wp_id   = (string)$c->comment_id;
		$parent  = (string)$c->comment_parent;
		$email   = (string)$c->comment_author_email;
		$name    = (string)$c->comment_author;
		$created = strtotime((string)$c->comment_date) ?: time();
		$text    = (string)$c->comment_content;
		$subject = Unicode::truncate(Html::decodeEntities(strip_tags($text)), 28, TRUE, TRUE);

		$uid = $this->cache['users'][$email] ?? 0;

		if ($this->config['dry_run']) {
			$map[$wp_id] = 999;
			return 999;
		}

		try {
			$data = [
				'entity_type'  => 'node',
				'entity_id'    => $nid,
				'field_name'   => $field,
				'uid'          => $uid,
				'created'      => $created,
				'status'       => 1,
				'subject'      => $subject,
				'comment_body' => ['value' => $text, 'format' => $this->config['comment_format'] ?: 'plain_text'],
			];

			// For anonymous users
			if ($uid === 0) {
				$data['name']     = $name;
				$data['mail']     = $email;
				$data['homepage'] = (string)$c->comment_author_url;
			}

			// Handle Threading
			if ($parent > 0 && isset($map[$parent])) {
				$data['pid'] = $map[$parent];
			}

			$comment = Comment::create($data);
			$comment->save();

			$cid = $comment->id();
			$map[$wp_id] = $cid;
			$this->cache['comments'][$wp_id] = $cid;

			return $cid;
		} catch (\Exception $e) {
			$this->log("Comment error: " . $e->getMessage());
			return null;
		}
	}

	// ==================================================
	// PHASE 4: FINALIZATION
	// ==================================================

	/**
	 * Saves the current migration mapping state to a JSON file.
	 *
	 * Persists the mapping of Users, Media, Terms, and Nodes to disk
	 * to allow resuming the script or running it in multiple passes.
	 *
	 * @return void
	 */
	private function saveCache() {
		file_put_contents($this->cacheFile, json_encode($this->cache, JSON_PRETTY_PRINT));
		$this->log("Cache saved");
	}

	/**
	 * Displays final statistics after the import process.
	 *
	 * prints the total execution time and the count of imported
	 * Users, Terms, Media, Nodes, and Comments.
	 *
	 * @return void
	 */
	private function showStats() {
		$this->header($this->config['dry_run'] ? "DRY RUN SUMMARY" : "COMPLETE");

		echo "\n" . self::C_BOLD . self::C_GREEN . "Statistics:\n" . self::C_RESET;
		echo "Users:    {$this->stats['users_created']} created, {$this->stats['users_mapped']} mapped\n";
		echo "Media:    {$this->stats['media_imported']} imported, {$this->stats['media_failed']} failed\n";
		if ($this->stats['media_replaced'] > 0) {
			echo "          {$this->stats['media_replaced']} URLs replaced\n";
		}
		if ($this->stats['terms_created'] > 0) {
		echo "Terms:    {$this->stats['terms_created']} created\n";
		}
		echo "Posts:    {$this->stats['posts_created']} created\n";
		echo "Pages:    {$this->stats['pages_created']} created\n";
		if ($this->stats['aliases_preserved'] > 0) {
			echo "Aliases:  {$this->stats['aliases_preserved']} preserved\n";
		}
		if ($this->stats['comments_created'] > 0) {
			echo "Comments: {$this->stats['comments_created']} created\n";
		}

		$this->log("=== Stats: " . json_encode($this->stats) . " ===");

		if ($this->config['dry_run']) {
			echo "\n" . self::C_YELLOW . "DRY RUN - No changes made\n" . self::C_RESET;
		} else {
			if ($this->prompt("\nClear cache? (y/n)", 'y', ['y', 'n'])) {
				exec(__DIR__ . '/vendor/bin/drush cr 2>&1', $output, $ret);
				$ret === 0 ? $this->success("✓ Cache cleared") : $this->warn("Run: drush cr");
			}

			$this->info("\nReview content in admin panel");
			if (!$this->config['auto_publish']) {
				$this->info("\nContent in DRAFT - publish after review");
			}
		}

		echo "\n" . str_repeat("=", 30) . "\n";
		$this->success("✓ Migration Complete!");
		echo self::C_CYAN . "Author: Salvatore Noschese\n" . self::C_RESET;
		echo self::C_CYAN . "Blog:   https://salvatorenoschese.it\n" . self::C_RESET;
		echo self::C_CYAN . "Donate: https://PayPal.me/SalvatoreN\n" . self::C_RESET;
		echo str_repeat("=", 30) . "\n\n";
	}

	// ==================================================
	// UI UTILITIES
	// ==================================================

	/**
	 * Prompts the user for input via the command line.
	 *
	 * @param string $text    The question/prompt to display.
	 * @param string $default The default value if the user presses Enter.
	 *
	 * @return string The user's input or the default value.
	 */
	private function prompt($question, $default = '', $allowed = null) {
		$suffix = $default !== '' ? " [$default]" : "";
		echo self::C_YELLOW . "$question$suffix: " . self::C_RESET;

		$input = trim(fgets(STDIN));
		$value = $input !== '' ? $input : $default;

		if ($allowed !== null) {
			if (is_array($allowed) && isset($allowed[0])) {
				if (!in_array($value, $allowed, true)) {
					$this->warn("Invalid. Using: $default");
					return $default;
				}
			}
		}

		if (strtolower($value) === 'y') return true;
		if (strtolower($value) === 'n') return false;
		if (is_numeric($value)) return (int)$value;

		return $value;
	}

	/**
	 * Asks the user to select an option from a list.
	 *
	 * @param string $text    The question to display.
	 * @param array  $options An associative array of options (key => label).
	 * @param string $default The key of the default option.
	 *
	 * @return string The key of the selected option.
	 */
	private function choose($label, $options, $default = null, $allowSkip = false) {
		echo $label . "\n";

		$map = [];
		$i = 1;
		foreach ($options as $id => $name) {
			$marker = (!$allowSkip && $default === $id) ? " " . self::C_GREEN . "[default]" . self::C_RESET : "";
			echo "  [$i] $name$marker\n";
			$map[$i] = $id;
			$i++;
		}

		if ($allowSkip) {
			echo "  [0] Skip " . self::C_GREEN . "[default]" . self::C_RESET . "\n";
			$map[0] = null;
		}

		$defaultIdx = $allowSkip ? 0 : ($default ? array_search($default, $map) : 1);
		$choice = $this->prompt("Select", $defaultIdx);
		$selected = $map[$choice] ?? ($allowSkip ? null : $map[$defaultIdx]);

		if ($selected === null) {
			$this->success("✓ Skipped");
		} else {
			$this->success("✓ " . $options[$selected]);
		}

		return $selected;
	}

	/**
	 * Displays a progress bar in the CLI.
	 *
	 * Overwrites the current line to show percentage progress.
	 *
	 * @param int $current The current item index.
	 * @param int $total   The total number of items.
	 *
	 * @return void
	 */
	private function progress($current, $total) {
		$percent = $total > 0 ? round(($current / $total) * 100) : 0;
		$filled = $total > 0 ? (int)(($current / $total) * 28) : 0;
		$bar = str_repeat("█", $filled) . str_repeat("░", 28 - $filled);
		echo "\r" . self::C_CYAN . "[$bar]" . self::C_RESET . " $percent%";
		if ($current >= $total) echo "\n";
	}

	/**
	 * Prints a formatted header to the console.
	 *
	 * @param string $text The header title.
	 * @return void
	 */
	private function header($text) {
		echo "\n" . str_repeat("=", 30) . "\n";
		echo self::C_BOLD . self::C_CYAN . $text . self::C_RESET . "\n";
		echo str_repeat("=", 30) . "\n";
	}

	/**
	 * Prints a success message in green.
	 *
	 * @param string $msg The message to display.
	 * @return void
	 */
	private function success($msg) {
		echo self::C_GREEN . $msg . self::C_RESET . "\n";
	}

	/**
	 * Prints an informational message in white/default color.
	 *
	 * @param string $msg The message to display.
	 * @return void
	 */
	private function info($msg) {
		echo self::C_WHITE . $msg . self::C_RESET . "\n";
	}

	/**
	 * Prints a warning message in yellow.
	 *
	 * @param string $msg The message to display.
	 * @return void
	 */
	private function warn($msg) {
		echo self::C_YELLOW . $msg . self::C_RESET . "\n";
	}

	/**
	 * Prints an error message in red.
	 *
	 * @param string $msg The message to display.
	 * @return void
	 */
	private function error($msg) {
		echo self::C_RED . $msg . self::C_RESET . "\n";
	}

	/**
	 * Appends a message to the migration log file.
	 *
	 * @param string $msg The message to log (timestamp is added automatically).
	 * @return void
	 */
	private function log($msg) {
		file_put_contents($this->logFile, "[" . date('H:i:s') . "] $msg\n", FILE_APPEND);
	}

	/**
	 * Clears Drupal entity memory caches and forces PHP garbage collection.
	 *
	 * Essential for long-running scripts to prevent memory leaks (Out of Memory errors).
	 *
	 * @param string|null $type Optional specific entity type storage to reset.
	 *
	 * @return void
	 */
	private function clearMemory($type = null) {
		$em = \Drupal::entityTypeManager();
		if ($type) $em->getStorage($type)->resetCache();
		$em->getStorage('node')->resetCache();
		$em->getStorage('file')->resetCache();
		gc_collect_cycles();
	}
}

// ==================================================
// ENTRY POINT
// ==================================================

try {
	$migrator = new WordPressMigrator();
	$migrator->run();
} catch (UserAbortException $e) {
	echo "\n" . WordPressMigrator::C_CYAN . "ℹ " . $e->getMessage() . WordPressMigrator::C_RESET . "\n\n";
} catch (\Exception $e) {
	if (isset($migrator) && isset($migrator->logFile)) {
		@file_put_contents($migrator->logFile, "[FATAL] " . $e->getMessage() . "\n", FILE_APPEND);
	}
	throw $e;
}
