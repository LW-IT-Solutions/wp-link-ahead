/**
 * Link Ahead – rich-text format for the block editor.
 *
 * Marks the selected text as a placeholder link to a post that is not
 * published yet: <a class="link-ahead" data-link-ahead="123">text</a>.
 * The front end turns it into a real link once the target is published.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var richText = wp.richText;
	var components = wp.components;
	var RichTextToolbarButton = wp.blockEditor.RichTextToolbarButton;
	var apiFetch = wp.apiFetch;
	var addQueryArgs = wp.url.addQueryArgs;

	var NAME = 'link-ahead/placeholder';
	var TYPES = [
		{ path: '/wp/v2/posts', label: __( 'Post', 'link-ahead' ) },
		{ path: '/wp/v2/pages', label: __( 'Page', 'link-ahead' ) },
	];

	var settings = {
		title: __( 'Link Ahead', 'link-ahead' ),
		tagName: 'a',
		className: 'link-ahead',
		attributes: {
			postId: 'data-link-ahead',
		},
		edit: Edit,
	};

	function statusText( item ) {
		var date = item.date ? item.date.replace( 'T', ' ' ).slice( 0, 16 ) : '';
		switch ( item.status ) {
			case 'future':
				/* translators: %s: date and time of publication. */
				return sprintf( __( 'Scheduled for %s', 'link-ahead' ), date );
			case 'draft':
				return __( 'Draft', 'link-ahead' );
			case 'pending':
				return __( 'Pending review', 'link-ahead' );
			case 'publish':
				return __( 'Published – shown as a link', 'link-ahead' );
			default:
				return item.status;
		}
	}

	function titleOf( item ) {
		var title = item.title && ( item.title.raw || item.title.rendered );
		return title || __( '(no title)', 'link-ahead' );
	}

	function currentPostId() {
		var editor = wp.data && wp.data.select( 'core/editor' );
		return editor && editor.getCurrentPostId ? editor.getCurrentPostId() : 0;
	}

	function search( term ) {
		var exclude = currentPostId();
		return Promise.all(
			TYPES.map( function ( type ) {
				return apiFetch( {
					path: addQueryArgs( type.path, {
						status: 'future,draft,pending',
						search: term,
						per_page: 10,
						context: 'edit',
						exclude: exclude || undefined,
						_fields: 'id,title,status,date',
					} ),
				} )
					.then( function ( items ) {
						return items.map( function ( item ) {
							item.typeLabel = type.label;
							return item;
						} );
					} )
					.catch( function () {
						return [];
					} );
			} )
		).then( function ( lists ) {
			return lists[ 0 ].concat( lists[ 1 ] );
		} );
	}

	function fetchOne( id ) {
		var tries = TYPES.map( function ( type ) {
			return apiFetch( {
				path: addQueryArgs( type.path + '/' + id, {
					context: 'edit',
					_fields: 'id,title,status,date',
				} ),
			} ).catch( function () {
				return null;
			} );
		} );
		return Promise.all( tries ).then( function ( found ) {
			return found[ 0 ] || found[ 1 ] || null;
		} );
	}

	/**
	 * Start and end of the placeholder around the caret or selection, so that
	 * "Remove" clears the whole placeholder and not only the selected letters.
	 */
	function placeholderRange( value ) {
		function has( index ) {
			var formats = value.formats[ index ] || [];
			return formats.some( function ( format ) {
				return format.type === NAME;
			} );
		}
		var start = value.start;
		var end = value.end;
		if ( start === end && ! has( start ) && start > 0 && has( start - 1 ) ) {
			start -= 1;
		}
		while ( start > 0 && has( start - 1 ) ) {
			start -= 1;
		}
		while ( end < value.text.length && has( end ) ) {
			end += 1;
		}
		return [ start, end ];
	}

	function Picker( props ) {
		var termState = useState( '' );
		var term = termState[ 0 ];
		var setTerm = termState[ 1 ];
		var resultState = useState( [] );
		var results = resultState[ 0 ];
		var setResults = resultState[ 1 ];
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];

		useEffect(
			function () {
				var stale = false;
				setBusy( true );
				var timer = setTimeout( function () {
					search( term ).then( function ( items ) {
						if ( ! stale ) {
							setResults( items );
							setBusy( false );
						}
					} );
				}, 250 );
				return function () {
					stale = true;
					clearTimeout( timer );
				};
			},
			[ term ]
		);

		return el(
			'div',
			{ className: 'link-ahead-picker' },
			el( components.TextControl, {
				label: __( 'Find a scheduled or draft post', 'link-ahead' ),
				value: term,
				onChange: setTerm,
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} ),
			busy
				? el( components.Spinner )
				: el(
						'ul',
						{ className: 'link-ahead-picker__list' },
						results.length
							? results.map( function ( item ) {
									return el(
										'li',
										{ key: item.id },
										el(
											components.Button,
											{
												className: 'link-ahead-picker__item',
												onClick: function () {
													props.onSelect( item );
												},
											},
											el( 'strong', null, titleOf( item ) ),
											el(
												'span',
												null,
												item.typeLabel + ' · ' + statusText( item )
											)
										)
									);
							  } )
							: el(
									'li',
									{ className: 'link-ahead-picker__empty' },
									__( 'No unpublished posts found.', 'link-ahead' )
							  )
				  )
		);
	}

	function Current( props ) {
		var itemState = useState( null );
		var item = itemState[ 0 ];
		var setItem = itemState[ 1 ];

		useEffect(
			function () {
				var stale = false;
				fetchOne( props.postId ).then( function ( found ) {
					if ( ! stale ) {
						setItem( found || false );
					}
				} );
				return function () {
					stale = true;
				};
			},
			[ props.postId ]
		);

		return el(
			'div',
			{ className: 'link-ahead-current' },
			null === item
				? el( components.Spinner )
				: el(
						'p',
						null,
						item
							? el(
									Fragment,
									null,
									el( 'strong', null, titleOf( item ) ),
									el( 'br' ),
									statusText( item )
							  )
							: sprintf(
									/* translators: %s: post ID. */
									__( 'Target #%s no longer exists or is not editable. The text stays unlinked.', 'link-ahead' ),
									props.postId
							  )
				  ),
			el(
				components.Button,
				{ variant: 'secondary', isDestructive: true, onClick: props.onRemove },
				__( 'Remove Link Ahead', 'link-ahead' )
			)
		);
	}

	function Edit( props ) {
		var openState = useState( false );
		var isOpen = openState[ 0 ];
		var setOpen = openState[ 1 ];
		var anchor = richText.useAnchor( {
			editableContentElement: props.contentRef.current,
			settings: settings,
		} );
		var canApply = props.isActive || ! richText.isCollapsed( props.value );

		function close() {
			setOpen( false );
		}

		return el(
			Fragment,
			null,
			el( RichTextToolbarButton, {
				icon: 'calendar-alt',
				title: __( 'Link Ahead', 'link-ahead' ),
				onClick: function () {
					setOpen( true );
				},
				isActive: props.isActive,
				isDisabled: ! canApply,
			} ),
			isOpen &&
				el(
					components.Popover,
					{
						anchor: anchor,
						placement: 'bottom',
						onClose: close,
						className: 'link-ahead-popover',
					},
					props.isActive && props.activeAttributes.postId
						? el( Current, {
								postId: props.activeAttributes.postId,
								onRemove: function () {
									var range = placeholderRange( props.value );
									props.onChange( richText.removeFormat( props.value, NAME, range[ 0 ], range[ 1 ] ) );
									close();
								},
						  } )
						: el( Picker, {
								onSelect: function ( item ) {
									props.onChange(
										richText.applyFormat( props.value, {
											type: NAME,
											attributes: { postId: String( item.id ) },
										} )
									);
									close();
								},
						  } )
				)
		);
	}

	richText.registerFormatType( NAME, settings );
} )( window.wp );
