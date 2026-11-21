# MASTER PROMPT — PicToSVG

## Project Identity

**Name:** PicToSVG  
**Tagline:** Turn Pictures into Precision Vectors  
**Type:** Web-based Image-to-SVG Converter  
**Purpose:** Convert JPG, PNG, WebP, and other raster images into clean, smooth, optimized SVG files suitable for laser engraving, CNC carving, and vector cutting.

## 1. Mission

Build a complete, functional, modern website named **PicToSVG**.

Users should be able to upload an image, automatically convert it to vector artwork, inspect the result, adjust its quality, and download a clean SVG.

**Core principle: Make complicated image vectorization extremely simple.**

The ideal workflow is:

**Upload Image → Convert to SVG → Preview and Adjust → Download SVG**

The website must be useful without requiring users to understand vector graphics, tracing algorithms, or SVG optimization.

Prioritize real conversion quality, smooth lines, fewer unnecessary vector nodes, and compatibility with common laser/CNC software.

Do not build an overly complicated graphics editor.

## 2. Technology Stack

Use the following technology:

- Backend: Latest stable Laravel
- Frontend: Laravel Blade
- CSS: Bootstrap 5 with custom dark theme
- JavaScript: Vanilla JS with lightweight libraries where helpful
- Image processing: ImageMagick or equivalent
- Vector tracing: VTracer and/or Potrace
- SVG processing: Secure SVG parser with path simplification and optimization
- Optional AI integration: Configurable image-redrawing provider
- Deployment: Ubuntu, Nginx, PHP-FPM

Keep the architecture modular.

Use existing reliable open-source image processing tools rather than unnecessarily implementing complex tracing algorithms from scratch.

No SPA, React, Vue, or Node server is required.

**MVP requirements:**
- No account registration
- No login
- No database required
- No payment system
- No subscriptions
- No unnecessary dependencies

All core functionality must work without an AI API key.

## 3. Main User Interface

Build a beautiful single-page interface using a dark charcoal background, red accent buttons, and clear typography.

The attached screenshots are design references.

Create three simple sections.

### Step 1 — Upload Image

Display a large drag-and-drop upload area.

Features:
- Drag and drop image
- Click to browse
- Paste image from clipboard
- Support JPG, JPEG, PNG, WebP
- Maximum size: 20 MB
- Display original image thumbnail
- Show filename and dimensions
- Change or remove image

After upload, automatically show the original image preview.

### Step 2 — Convert to SVG

Display one large primary button:

**Convert to SVG**

Provide three conversion presets:

1. **Engrave (Default)** — Produce clean black-and-white vector artwork with preserved filled regions and details.
2. **Outline Cut** — Extract clean closed contours for laser/CNC vector cutting.
3. **Line Art** — Produce clean black line artwork suitable for engraving, with minimal noise and unnecessary detail.

Choose automatic recommended settings for each preset.

Include a checkbox:

**Optimize Smooth Curves — Enabled by Default**

An optional **AI Redraw** button may appear when an AI provider is configured. It can simplify complicated photographic images into clean black-and-white artwork before tracing.

AI redrawing must be optional and must never block normal image-to-SVG conversion.

Do not label ordinary image thresholding or traditional vectorization as AI.

### Step 3 — Preview and Download

Display two large preview panels:

**Original Image | SVG Result**

Allow the user to:
- Zoom in and out
- Pan the image
- Fit to screen
- Switch between side-by-side and SVG-only views
- View the result on transparent or white backgrounds

Display basic vector information:
- Number of paths
- Number of nodes
- Output width and height
- Estimated file size

Provide one prominent button:

**Download SVG**

The downloaded file must contain real SVG vector paths, not a raster image embedded inside an SVG container.

## 4. Vector Conversion Engine

Build a reliable processing pipeline:

1. Validate the uploaded image.
2. Correct orientation and handle transparency.
3. Convert the image into an appropriate working format.
4. Apply grayscale or color normalization.
5. Improve contrast where appropriate.
6. Remove small isolated noise.
7. Apply thresholding based on the selected preset.
8. Trace bitmap regions into vector geometry.
9. Fit smooth curves to traced boundaries.
10. Remove unnecessary nodes and simplify paths.
11. Preserve intentional corners, holes, and important details.
12. Validate the resulting geometry.
13. Produce clean SVG output.

Prefer cubic Bézier curves and clean contours instead of pixel-by-pixel stair-step geometry.

For the default engraving preset, prioritize visual quality and reliable filled geometry.

For outline-cut mode, prioritize closed paths, stable contours, and avoidance of duplicated overlapping cut segments.

For line-art mode, trace the visible black artwork faithfully. Do not claim that ordinary outline tracing produces true centerline toolpaths.

Design a separate centerline-tracing feature for future implementation if needed.

## 5. Automatic SVG Optimization

**Smooth, optimized vectors are the most important feature of PicToSVG.**

Implement:

- Path simplification
- Bézier curve fitting
- Excess-node reduction
- Tiny speck removal
- Small artifact removal
- Nearly straight segment simplification
- Duplicate geometry detection
- Unnecessary overlapping path cleanup
- Appropriate path closure
- SVG decimal precision optimization
- Preservation of intended sharp corners
- Preservation of important interior detail

Optimize SVG file size without noticeably degrading engraving quality.

Do not blindly merge paths when doing so changes holes, fills, or cutting behavior.

Prevent excessive smoothing that destroys logos, letters, fine details, or geometric shapes.

A good result should use as few nodes as practical while preserving the shape.

Use different optimization tolerances depending on output resolution and user settings.

Do not apply aggressive optimization simply to achieve a smaller path count.

## 6. Simple Adjustment Controls

By default, show only three sliders:

| Setting | Default | Purpose |
|---|---|---|
| Detail | 7/10 | Preserve fine image details |
| Smoothing | 8/10 | Reduce unnecessary nodes and rough edges |
| Noise Removal | 5/10 | Remove small unwanted artifacts |

Hide all advanced tracing options inside a collapsible section labeled:

**Advanced Settings**

Advanced settings may include:
- Black-and-white threshold
- Image contrast
- Invert black/white
- Curve simplification tolerance
- Minimum speck size
- Corner preservation
- Outline-only output
- Transparent background

Changes should update the preview through a debounced conversion process.

Display a loading indicator during processing.

Provide a **Reset Settings** button.

The default settings must produce good results without requiring any adjustments for ordinary logos and simple artwork.

## 7. SVG Output Requirements

Generated SVG files must:

- Contain valid SVG markup
- Contain actual vector geometry
- Use a correct viewBox
- Preserve aspect ratio
- Support real-world dimensions
- Avoid unnecessary metadata
- Avoid embedded raster images
- Avoid unsupported filters or effects
- Use standard path elements
- Use safe SVG content
- Preserve holes and fill rules
- Avoid unnecessary group transforms
- Support black-and-white laser engraving

Include an output width field with selectable units:

- Millimeters (default)
- Inches

Automatically calculate height from the original aspect ratio.

Example:

If width is set to 150 mm, the exported SVG should have a physical width of 150 mm, with a corresponding height and correctly proportioned viewBox.

**Physical sizing must not deform the design.**

Optimize for import into:
- LightBurn
- Vectric Aspire
- VCarve
- Inkscape
- Other common SVG-compatible CNC and laser applications

Do not generate G-code.

For cutting mode, provide a warning when geometry includes detected open paths or potentially problematic intersections.

Do not represent automated SVG validation as a guarantee that a design is physically safe to cut.

## 8. Optional AI Redraw Feature

Provide a modular AI integration layer.

If configured, allow the user to click:

**Redraw with AI**

AI redraw should attempt to:
- Simplify complicated photographic backgrounds
- Isolate the main subject
- Create clear black-and-white line art
- Preserve recognizable shapes
- Reduce irrelevant details
- Make the image easier to vectorize

The process should be:

Original Image → AI Redraw → SVG Trace → Optimization

Let the user compare the original image and the redrawn image.

Never overwrite the uploaded original.

Users must be able to choose between tracing the original image and tracing the AI-redrawn version.

Store API credentials exclusively on the server.

If AI is not configured, hide or disable the feature with a clear explanation.

The core website must remain fully functional.

## 9. Backend Architecture

Use a clean Laravel MVC architecture.

Suggested components:

**Controllers**
- ImageUploadController
- SvgConversionController
- SvgDownloadController

**Services**
- ImagePreprocessingService
- VectorTracingService
- SvgOptimizationService
- SvgValidationService
- SvgExportService
- OptionalAiRedrawService

**Frontend**
- One main Blade page
- Reusable Blade components
- One JavaScript module for uploads and preview
- Bootstrap 5 styling

Suggested endpoints:

- POST /api/images/upload
- POST /api/svg/convert
- POST /api/svg/optimize
- GET /api/svg/download/{temporaryId}

Use secure temporary processing directories.

Enforce upload size, image dimensions, MIME validation, processing timeouts, and bounded resource consumption.

Prevent arbitrary file access, command injection, unsafe SVG markup, and cross-user access to temporary files.

Delete temporary files automatically after a configurable expiration period.

Use Laravel's secure process execution facilities for external tracing programs.

Do not expose system paths or internal commands to clients.

Prefer stateless operation without a database.

If conversion becomes resource-intensive, support Laravel queues as an optional later enhancement.

## 10. UI/UX Design Requirements

The website should feel like a polished commercial application while remaining extremely easy to operate.

Design direction:
- Dark background
- Red primary action buttons
- Rounded panels
- Clean typography
- Minimal visual clutter
- Responsive desktop and mobile layouts
- Large preview area
- Clear upload instructions
- Convenient download button

On desktop, use a two-column original/result preview.

On mobile, stack preview panels vertically.

Show meaningful loading states and errors.

Do not expose technical terminology such as path precision, spline iteration, or bitmap segmentation in the default interface.

Use plain-language labels.

The user should be able to successfully download an SVG with approximately three interactions:

1. Upload image
2. Click Convert
3. Click Download

## 11. Testing and Quality Requirements

Include automated tests for:

- Image upload validation
- Conversion success
- SVG XML validity
- Correct physical dimensions
- Aspect-ratio preservation
- Transparent image handling
- Background removal behavior
- Path simplification
- Tiny noise cleanup
- Preservation of holes
- Cut contour closure
- Download authorization
- Temporary file cleanup
- Invalid and malicious upload rejection
- Missing AI configuration

Test conversion using:
- Simple black logo on white background
- Transparent PNG logo
- Text-heavy image
- Detailed line drawing
- Photograph containing a vehicle
- Complex illustration

Validate visually that smoothing does not destroy important geometry.

Treat complex photographs as approximate vectorizations unless separately simplified or redrawn.

## 12. Development Phases

### Phase 1 — Working MVP

Implement:
- Laravel application
- Single-page interface
- Image upload
- Black-and-white conversion
- Real vector tracing
- SVG optimization
- Side-by-side preview
- SVG download
- Millimeter/inch sizing

This phase must be fully usable.

### Phase 2 — Improved Processing

Implement:
- Detail slider
- Smoothing slider
- Noise-removal slider
- Additional conversion presets
- Advanced settings
- Improved contour analysis
- Better visual comparison

### Phase 3 — Optional AI Enhancements

Implement:
- AI redraw integration
- Subject simplification
- Original-versus-redrawn comparison
- Trace AI-generated output

Do not make Phase 3 a prerequisite for Phase 1.

## 13. Documentation

Create a `/docs` folder containing:

- PROJECT-OVERVIEW.md
- ARCHITECTURE.md
- FEATURES.md
- IMAGE-PROCESSING.md
- SVG-OPTIMIZATION.md
- API.md
- INSTALLATION.md
- TESTING.md
- FUTURE-ENHANCEMENTS.md

Include installation instructions for Ubuntu/Nginx and required tracing tools.

Document actual commands, dependencies, configuration, and tested conversion settings.

## 14. Development Instructions for the AI Agent

You are the lead full-stack developer and image-processing engineer for PicToSVG.

Your responsibility is to build a fully operational website, not simply a prototype or mockup.

Follow these principles:

1. Keep the website simple and intuitive.
2. Use established image tracing libraries.
3. Prioritize smooth SVG quality over excessive features.
4. Build reusable Laravel services.
5. Implement real functionality; no fake conversion results.
6. Never embed a PNG or JPG inside an SVG as a substitute for tracing.
7. Minimize unnecessary JavaScript libraries.
8. Avoid a database unless a genuine requirement emerges.
9. Keep the optional AI feature isolated from the standard conversion engine.
10. Write clean, maintainable, documented code.
11. Test actual vector exports.
12. Make reasonable technical decisions without asking unnecessary questions.
13. Track progress and completed features in documentation.
14. Complete and verify the working MVP before expanding features.

**Begin implementation immediately.**

First inspect the existing repository, if one exists. Then establish the project structure, install necessary dependencies, implement the SVG conversion pipeline, build the interface, and verify a complete upload-to-download workflow.

The final deliverable must be a working PicToSVG website that produces usable, optimized SVG files for laser engraving and CNC workflows.

**Success criterion:** A user uploads a picture, clicks Convert to SVG, sees clean and smooth vector artwork, and downloads a real, correctly sized SVG without needing knowledge of vector editing software.