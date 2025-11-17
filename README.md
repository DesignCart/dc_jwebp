# DC JWebP

DC JWebP is a lightweight and fully automatic Joomla system plugin that optimizes images on your website by converting JPG and PNG files to WebP, replacing them in the final HTML output, and enabling native lazy loading. The plugin requires no configuration, introduces no external dependencies, and works instantly after activation — significantly improving PageSpeed scores and overall site performance.

## Features
- Automatic JPG/PNG → WebP conversion  
- Replaces image paths in the final HTML output  
- Supports <img> and inline CSS background-image  
- Native lazy loading (`loading="lazy"`) — no JavaScript required  
- Converts images only once, then serves cached WebP files  
- Compatible with Joomla 4, 5, and 6  
- Works with SP Page Builder, Helix Ultimate, YOOtheme, and custom templates  
- Zero impact on database or template files  
- No external libraries required (uses PHP GD)

## Configuration
The plugin includes a simple configuration panel with:
- WebP quality (0–100)  
- Excluded folders  
- Lazy loading on/off  

## Installation
1. Download the ZIP package.  
2. Install it via **Joomla → Extensions → Install**.  
3. Enable the plugin under **System → Plugins → System – DC JWebP**.  
4. (Optional) Adjust quality or excluded paths.

## Compatibility
- Joomla 4.x, 5.x, 6.x  
- PHP 8.1 – 8.3  
- SP Page Builder  
- Helix Ultimate  
- YOOtheme  
- Any Bootstrap-based or custom template

## License
Released under the GPL license.

## Author
Design Cart – https://www.designcart.pl
