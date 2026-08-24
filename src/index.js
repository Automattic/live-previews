/**
 * Live Previews editor integration.
 *
 * Adds a "Live Previews" panel to the post sidebar with two actions: generate a
 * new shareable preview link (expiration + a viewer cap), and manage the post's
 * existing links (see their usage and time left, and revoke them).
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel, store as editorStore } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Flex,
	FlexBlock,
	FlexItem,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

const REST_BASE = '/live-previews/v1/preview-links';

const settings = window.livePreviews || {
	expirationOptions: [],
	defaultExpiration: 28800,
};

const expirationOptions = ( settings.expirationOptions || [] ).map( ( option ) => ( {
	label: option.label,
	value: String( option.seconds ),
} ) );

/**
 * A short, human relative time until a Unix timestamp, e.g. "in about 5 hours".
 */
function timeUntil( targetSeconds ) {
	const remaining = targetSeconds - Math.floor( Date.now() / 1000 );

	if ( remaining <= 0 ) {
		return __( 'expired', 'live-previews' );
	}

	const units = [
		[ 86400, __( 'day', 'live-previews' ), __( 'days', 'live-previews' ) ],
		[ 3600, __( 'hour', 'live-previews' ), __( 'hours', 'live-previews' ) ],
		[ 60, __( 'minute', 'live-previews' ), __( 'minutes', 'live-previews' ) ],
		[ 1, __( 'second', 'live-previews' ), __( 'seconds', 'live-previews' ) ],
	];

	for ( const [ size, singular, plural ] of units ) {
		if ( remaining >= size ) {
			const count = Math.floor( remaining / size );
			return sprintf(
				/* translators: 1: a number, 2: a unit of time such as "hours". */
				__( 'expires in %1$d %2$s', 'live-previews' ),
				count,
				1 === count ? singular : plural
			);
		}
	}

	return __( 'expires soon', 'live-previews' );
}

function usageLabel( link ) {
	if ( null === link.max_uses ) {
		return sprintf(
			/* translators: %d: number of times the link has been viewed. */
			_n( '%d view · no limit', '%d views · no limit', link.use_count, 'live-previews' ),
			link.use_count
		);
	}

	return sprintf(
		/* translators: 1: views so far, 2: maximum views. */
		__( '%1$d of %2$d views', 'live-previews' ),
		link.use_count,
		link.max_uses
	);
}

function GenerateModal( { postId, onClose } ) {
	const [ expiration, setExpiration ] = useState( String( settings.defaultExpiration ) );
	const [ maxUses, setMaxUses ] = useState( '' );
	const [ url, setUrl ] = useState( '' );
	const [ isBusy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ copied, setCopied ] = useState( false );

	const copyLink = async () => {
		setBusy( true );
		setError( '' );

		try {
			const response = await apiFetch( {
				path: REST_BASE,
				method: 'POST',
				data: {
					post_id: postId,
					expiration: parseInt( expiration, 10 ),
					max_uses: '' === maxUses ? null : parseInt( maxUses, 10 ),
				},
			} );

			setUrl( response.url );

			try {
				await window.navigator.clipboard.writeText( response.url );
				setCopied( true );
			} catch ( clipboardError ) {
				// Clipboard access can be denied; the link is shown for manual copy.
				setCopied( false );
			}
		} catch ( requestError ) {
			setError(
				requestError.message ||
					__( 'The preview link could not be generated.', 'live-previews' )
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<Modal title={ __( 'Generate preview link', 'live-previews' ) } onRequestClose={ onClose }>
			<Notice status="warning" isDismissible={ false }>
				{ __( 'Anyone with this link will be able to preview the post.', 'live-previews' ) }
			</Notice>

			<SelectControl
				label={ __( 'Link expiration', 'live-previews' ) }
				value={ expiration }
				options={ expirationOptions }
				onChange={ setExpiration }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>

			<TextControl
				type="number"
				min={ 1 }
				step={ 1 }
				label={ __( 'Maximum uses', 'live-previews' ) }
				help={ __( 'Number of distinct viewers. Leave empty for unlimited.', 'live-previews' ) }
				value={ maxUses }
				onChange={ setMaxUses }
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ url && (
				<TextControl
					label={ __( 'Preview link', 'live-previews' ) }
					value={ url }
					readOnly
					onFocus={ ( event ) => event.target.select() }
					help={
						copied
							? __( 'Copied to clipboard.', 'live-previews' )
							: __( 'Copy this link to share it.', 'live-previews' )
					}
					__nextHasNoMarginBottom
				/>
			) }

			<Button
				variant="primary"
				onClick={ copyLink }
				isBusy={ isBusy }
				disabled={ isBusy || ! postId }
				style={ { marginTop: '1rem' } }
			>
				{ __( 'Copy Link', 'live-previews' ) }
			</Button>
		</Modal>
	);
}

function ManageModal( { postId, onClose } ) {
	const [ links, setLinks ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ , setTick ] = useState( 0 );

	const load = async () => {
		try {
			const data = await apiFetch( {
				path: `${ REST_BASE }?post_id=${ postId }`,
			} );
			setLinks( data );
		} catch ( requestError ) {
			setError( requestError.message || __( 'Could not load links.', 'live-previews' ) );
			setLinks( [] );
		}
	};

	useEffect( () => {
		load();
		// Re-render every 30 seconds so the "expires in …" labels stay current.
		const timer = window.setInterval( () => setTick( ( t ) => t + 1 ), 30000 );
		return () => window.clearInterval( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const revoke = async ( id ) => {
		setLinks( ( current ) => current.filter( ( link ) => link.id !== id ) );
		try {
			await apiFetch( {
				path: `${ REST_BASE }/${ id }?post_id=${ postId }`,
				method: 'DELETE',
			} );
		} catch ( requestError ) {
			// Put it back and surface the problem if the revoke did not stick.
			setError( requestError.message || __( 'Could not revoke the link.', 'live-previews' ) );
			load();
		}
	};

	return (
		<Modal title={ __( 'Manage preview links', 'live-previews' ) } onRequestClose={ onClose }>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			{ null === links && <Spinner /> }

			{ null !== links && 0 === links.length && (
				<p>{ __( 'No active preview links.', 'live-previews' ) }</p>
			) }

			{ null !== links &&
				links.map( ( link ) => (
					<Flex key={ link.id } align="center" style={ { padding: '8px 0', borderBottom: '1px solid #f0f0f0' } }>
						<FlexBlock>
							<div>{ usageLabel( link ) }</div>
							<div style={ { color: '#757575', fontSize: '12px' } }>
								{ timeUntil( link.expires_at ) }
							</div>
						</FlexBlock>
						<FlexItem>
							<Button variant="tertiary" isDestructive onClick={ () => revoke( link.id ) }>
								{ __( 'Revoke', 'live-previews' ) }
							</Button>
						</FlexItem>
					</Flex>
				) ) }
		</Modal>
	);
}

function LivePreviewsPanel() {
	const { postId, status } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postId: editor.getCurrentPostId(),
			status: editor.getEditedPostAttribute( 'status' ),
		};
	}, [] );

	const [ openModal, setOpenModal ] = useState( '' );

	// A published post is already public, so a preview link is meaningless.
	if ( 'publish' === status ) {
		return null;
	}

	return (
		<PluginDocumentSettingPanel name="live-previews" title={ __( 'Live Previews', 'live-previews' ) }>
			<Button
				variant="secondary"
				onClick={ () => setOpenModal( 'generate' ) }
				disabled={ ! postId }
				style={ { marginBottom: '8px' } }
			>
				{ __( 'Generate preview link', 'live-previews' ) }
			</Button>

			<Button variant="tertiary" onClick={ () => setOpenModal( 'manage' ) } disabled={ ! postId }>
				{ __( 'Manage preview links', 'live-previews' ) }
			</Button>

			{ 'generate' === openModal && (
				<GenerateModal postId={ postId } onClose={ () => setOpenModal( '' ) } />
			) }

			{ 'manage' === openModal && (
				<ManageModal postId={ postId } onClose={ () => setOpenModal( '' ) } />
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'live-previews', { render: LivePreviewsPanel } );
