# WordPress to Drupal 11 Migration Script

A standalone, interactive **Drush script** to migrate content from WordPress to Drupal 11.

![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)
![Drupal: 11.x](https://img.shields.io/badge/Drupal-11.x-0678BE.svg)
![PHP: 8.1+](https://img.shields.io/badge/PHP-8.1+-777BB4.svg)
![Type: Standalone Script](https://img.shields.io/badge/type-standalone%20script-orange)

## ✨ Features

- **Interactive Wizard** - Step-by-step configuration with smart defaults
- **Dry Run Mode** - Test your migration before making any changes
- **Content Cleaning** - Automatically removes Gutenberg blocks and cleans HTML
- **URL Preservation** - Maintains WordPress permalinks as Drupal path aliases
- **Complete Media Import** - Downloads and imports images with alt text preservation
- **Comment Threading** - Preserves parent-child comment relationships
- **Smart Detection** - Auto-detects content types, vocabularies, and field mappings
- **Resume Capability** - Cache system allows continuing interrupted migrations
- **Progress Feedback** - Real-time progress bars and colored console output
- **Detailed Logging** - Comprehensive logs for troubleshooting

## 👥 Who Is This For?

This tool is ideal if you:
- Need a one-time WordPress → Drupal migration
- Prefer an interactive CLI wizard over YAML configs
- Want full control and visibility over the process
- Are migrating legacy or messy WordPress content

## 📋 What Gets Migrated

- ✅ Posts and Pages
- ✅ Media (images, documents, video, audio)
- ✅ Categories and Tags (as taxonomy terms)
- ✅ Comments (with threading support)
- ✅ Authors (create users or map to admin)
- ✅ Featured Images
- ✅ URL aliases (permalinks)
- ✅ Post dates and metadata

## 🚀 Quick Start

### Prerequisites

- **Drupal 11.x** (CMS or Core)
- **Drush** (included with Drupal CMS, or install via `composer require drush/drush`)
- **Media module** (for importing attachments)
- **Comment module** (optional, for comments)
- **PHP 8.1+** with SimpleXML extension

### Installation

1. **Export your WordPress content:**
   - In WordPress admin, go to **Tools → Export**
   - Select "All content" and download the XML file

2. **Place the script in your Drupal root:**
   ```bash
   cd /path/to/drupal
   # Download the script
   wget https://raw.githubusercontent.com/SalvatoreNoschese/wp2drupal-migrator/main/wp2dr-migrate.php
   # Or use git clone
   git clone https://github.com/SalvatoreNoschese/wp2drupal-migrator.git
   cd wp2drupal-migrator
   ```

3. **Place your WordPress XML file in the same directory:**
   ```bash
   cp ~/Downloads/wordpress-export.xml .
   ```

4. **Run the migration:**
   ```bash
   drush scr wp2dr-migrate.php
   ```

That's it! The wizard will guide you through the rest.

## 📖 Usage Guide

### Basic Workflow

1. **System Check** - Verifies Drupal version and modules
2. **XML Analysis** - Scans your export file and shows what will be imported
3. **Environment Scan** - Detects available content types and vocabularies
4. **Configuration Wizard** - Interactive setup (text formats, mappings, options)
5. **Confirmation** - Choose between Dry Run (test) or Live Import
6. **Execution** - Imports content with progress feedback
7. **Statistics** - Shows detailed results

### Configuration Options

During the wizard, you'll configure:

- **Text Formats**: Choose formats for content and comments
- **Content Types**: Map WordPress posts/pages to Drupal content types
- **Vocabularies**: Map categories and tags to Drupal taxonomies
- **Comment Types**: Select comment type (if Comment module enabled)
- **Media Import**: Choose whether to download and import media files
- **User Strategy**: Create new users or map all to admin account
- **Publishing**: Auto-publish or save as drafts

### Dry Run vs Live Import

**Dry Run Mode** (recommended first):
- Simulates the entire process
- No database changes
- Shows what would be created
- Validates configuration

**Live Import**:
- Makes actual changes to database
- Downloads media files
- Creates content entities
- Preserves data in cache for resume capability

## 🔧 Configuration Requirements

### For Drupal CMS (Recommended)

Drupal CMS includes everything needed by default. You only need to:

1. **Enable Comment module** (if importing comments):
   ```bash
   drush en comment -y
   ```

2. **Create a comment type** (if importing comments):
   - Go to: `/admin/structure/comment`
   - Add a comment type for your content

3. **Create vocabularies** (if importing categories/tags):
   - Included by default in Drupal CMS

### For Drupal Core

Install required components:

```bash
# Install Drush (already present in CMS)
composer require drush/drush

# Enable Media module (already present in CMS)
drush en media -y

# Enable Comment module (optional > only if you want to import Comments)
drush en comment -y
```

## 📁 File Structure

After running, the script creates a `_wp2dr_data/` directory:

```
_wp2dr_data/
├── cache.json          # Entity mappings (for resume)
├── current.log         # Detailed migration log
└── _archive/           # Previous runs
    ├── log_20250102_143022.log
    └── cache_20250102_143022.json
```

## 🎯 Advanced Features

### Resume Capability

If the migration is interrupted, simply run the script again. The cache system will:
- Skip already imported users
- Skip already imported media
- Skip already imported content
- Continue from where it stopped

### Cache Management

The script offers cache options on each run:
- **Use cache**: Resume from previous run
- **Start fresh**: Archive old cache and start over

### Memory Optimization

The script includes automatic memory management:
- Periodic entity cache clearing
- Garbage collection
- Suitable for large XML files (tested with 1000+ posts)

## ⚠️ Important Notes

> [!WARNING]
> **Field Configuration Requirement:** Before starting the migration, ensure your Drupal Content Types have the necessary fields assigned. For categories, tags, or comments to be linked correctly, you must manually add the corresponding **Entity Reference** (Taxonomy) and **Comment** fields to your target Content Types. If these fields are missing, the content will be imported but the relationships will be lost.

### Before Running

1. **Backup your database** (always!)
2. **Test with Dry Run** first
3. **Review text format permissions** (ensure they allow the HTML you need)
4. **Check disk space** (for media downloads)

### Known Limitations

- Does not migrate WordPress plugins/widgets
- Does not migrate theme settings
- Custom post types require manual configuration
- Very large XML files (>1GB) may require PHP memory_limit adjustment

### Troubleshooting

**"No text formats found"**
- Create at least one text format in Drupal (`/admin/config/content/formats`)

**"Media module not enabled"**
- Run: `drush en media -y`

**"No comment types found"**
- Create a comment type at `/admin/structure/comment`

**Memory issues**
- Increase PHP memory: `php -d memory_limit=1G vendor/bin/drush scr wp2dr-migrate.php`

## 📊 What Makes This Different

This script was built to address common frustrations with existing migration tools:

| Feature | This Script | Typical Approach |
|---------|-------------|------------------|
| Setup | Interactive wizard | Manual YAML configuration |
| Testing | Built-in dry run | None (risky!) |
| Gutenberg | Automatically cleaned | Left as-is (messy) |
| URL Aliases | Preserved | Often lost |
| Comments | Full threading | Flat structure |
| Media Alt Text | Preserved | Often lost |
| Resume | Cache-based | Start from scratch |
| Feedback | Progress bars & colors | Silent or verbose logs |

## 🤝 Contributing

Contributions are welcome! Please feel free to submit issues or pull requests.

### Reporting Bugs

When reporting issues, please include:
- WordPress version used for export
- Drupal version
- XML file size (approximate)
- Relevant log entries from `_wp2dr_data/current.log`

### Feature Requests

Open an issue with the `enhancement` label and describe:
- The use case
- Expected behavior
- Why it would be useful

## 📝 License

MIT License - see [LICENSE](LICENSE) file for details.

## 🎨 Screenshots

<table style="width:100%; text-align:center;">
  <tr>
    <td style="width:25%;">
      <a href="https://github.com/user-attachments/assets/b3f50a14-2f06-4f7d-b4e8-9428f199f852" target="_blank">
        <img src="https://github.com/user-attachments/assets/b3f50a14-2f06-4f7d-b4e8-9428f199f852" alt="Ftp View" width="150" height="150" style="object-fit:cover; border-radius:4px;"/>
      </a>
      <div>Ftp View</div>
    </td>
    <td style="width:25%;">
      <a href="https://github.com/user-attachments/assets/0239e236-cb3c-4f3f-8c83-d86af4a916a1" target="_blank">
        <img src="https://github.com/user-attachments/assets/0239e236-cb3c-4f3f-8c83-d86af4a916a1" alt="If there is any issue" width="150" height="150" style="object-fit:cover; border-radius:4px;"/>
      </a>
      <div>If there is any issue</div>
    </td>
    <td style="width:25%;">
      <a href="https://github.com/user-attachments/assets/6d2dfdf6-6c53-4812-a680-ac4b3edb09e8" target="_blank">
        <img src="https://github.com/user-attachments/assets/6d2dfdf6-6c53-4812-a680-ac4b3edb09e8" alt="Cpanel Dry Run" width="150" height="150" style="object-fit:cover; border-radius:4px;"/>
      </a>
      <div>Cpanel Dry Run</div>
    </td>
      <td style="width:25%;">
      <a href="https://github.com/user-attachments/assets/ec0ffab5-442d-471b-b8d0-54e9ee13c195" target="_blank">
         <img alt="Real Import" src="https://github.com/user-attachments/assets/ec0ffab5-442d-471b-b8d0-54e9ee13c195" width="150" height="150" style="object-fit:cover; border-radius:4px;"/>
      </a>
      <div>Cpanel Real Import</div>
    </td>
  </tr>
</table>


## 👤 Author

**Salvatore Noschese**

- Website:  [https://salvatorenoschese.it](https://salvatorenoschese.it)
- DemoLink: [https://cms.salvatorenoschese.it](https://cms.salvatorenoschese.it)
- Donate:   [PayPal.me/SalvatoreN](https://paypal.me/SalvatoreN)

## 🙏 Acknowledgments

Built with the Drupal community in mind. Special thanks to everyone who provided feedback and testing.

---

**Note**: This is an independent tool and is not affiliated with the official Drupal WordPress Migrate module. It's offered as an alternative approach that may better suit certain use cases.

## ⭐ Support

If this tool helped you, please:
- Star the repository on GitHub
- Share it with others who might need it
- Consider a small donation to support development

**Happy migrating!** 🚀
