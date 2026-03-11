/**
 * Shelter Donations - Legacy stores entry point.
 *
 * This file is a compatibility shim. All store logic now lives in
 * individual modules under assets/js/stores/.
 *
 * Each block's view.js imports only the store it needs, so this file
 * is only loaded when explicitly enqueued (e.g. pages that need all stores).
 *
 * @package Starter_Shelter
 * @since 2.0.0
 * @deprecated Use individual store modules instead.
 */

export * from './stores/index.js';
