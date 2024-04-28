See ./wp-taxonomy-category-synchronization/ for the plugin files.

Designed for WP Job Manager and the Cariera theme, this plugin synchronizes taxonomy categories across 'resume_category', 'company_category', 'job_listing_category'.

The plugin also includes handling of specific metadata fields like 'cariera_background_image', 'cariera_image_icon', 'cariera_font_icon'. The metadata is correctly copied during synchronization.

Special consideration has been provided to support WP All Import. A system limitation on WP All Import side requires imports to run one record at a time.

WP Taxonomy Category Synchronization can be easily extended to other taxonomy categories, and/or modified for other themes.

A brief overview of how the plugin functions:

![PlantUML Diagram](/documentation/images/diagram.png)
