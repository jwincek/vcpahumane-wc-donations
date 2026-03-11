/**
 * Memorial Wall Block — Frontend Interactivity
 *
 * Imports the memorials store via relative URL. When WordPress loads this
 * file as a script module (via the viewScriptModule declaration in
 * block.json), the browser resolves the relative path against this
 * module's own URL — e.g. .../blocks/memorial-wall/view.js resolves
 * ../../assets/js/stores/memorials.js to .../assets/js/stores/memorials.js.
 *
 * We use a relative URL rather than a bare specifier (like
 * 'starter-shelter/memorials') because bare specifiers require an import
 * map entry, which WordPress only generates on the frontend. The block
 * editor's iframe may not include custom import map entries, causing a
 * "bare specifier not remapped" error.
 *
 * @package Starter_Shelter
 * @since   2.1.0
 */

import '../../assets/js/stores/memorials.js';
