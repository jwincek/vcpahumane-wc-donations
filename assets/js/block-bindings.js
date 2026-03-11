/**
 * Register Starter Shelter block binding sources in the editor.
 *
 * PHP register_block_bindings_source() handles server-side rendering,
 * but the editor needs client-side registration via
 * registerBlockBindingsSource() to recognize custom sources.
 * Without this, the editor shows "Source not registered" for bound blocks.
 *
 * @since 2.2.0
 */
( function () {
	var registerBlockBindingsSource = wp.blocks.registerBlockBindingsSource;

	registerBlockBindingsSource( {
		name: 'starter-shelter/entity',
		label: 'Shelter Entity Data',
	} );

	registerBlockBindingsSource( {
		name: 'starter-shelter/donor',
		label: 'Shelter Donor Data',
	} );

	registerBlockBindingsSource( {
		name: 'starter-shelter/stats',
		label: 'Shelter Statistics',
	} );

	registerBlockBindingsSource( {
		name: 'starter-shelter/campaign',
		label: 'Campaign Data',
	} );

	registerBlockBindingsSource( {
		name: 'starter-shelter/tier',
		label: 'Membership Tier Data',
	} );
} )();
