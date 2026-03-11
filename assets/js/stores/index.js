/**
 * Shelter Donations - Store Index
 *
 * Re-exports all stores for convenience.
 * Import individual stores for better code splitting.
 *
 * @package Starter_Shelter
 * @since 2.0.0
 */

// Utility functions
export * from './utils.js';

// Form stores
export * as donationForm from './donation-form.js';
export * as memorialForm from './memorial-form.js';
export * as membershipForm from './membership-form.js';

// Archive/listing stores
export * as memorials from './memorials.js';
export * as donations from './donations.js';

// Widget stores
export * as campaigns from './campaigns.js';
export * as donor from './donor.js';
