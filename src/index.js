/**
 * Live Previews editor integration.
 *
 * Adds a "Generate preview link" control to the post sidebar that opens a modal
 * (expiration + one-time use), mints a link via the REST endpoint, shows it, and
 * copies it to the clipboard.
 */

import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel, store as editorStore } from '@wordpress/editor';
import { useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const settings = window.livePreviews || {
	expirationOptions: [],
	defaultExpiration: 28800,
};

const expirationOptions = ( settings.expirationOptions || [] ).map( ( option ) => ( {
	label: option.label,
	value: String( option.seconds ),
} ) );

function GeneratePreviewLink() {
	const postId = useSelect(
		( select ) => select( editorStore ).getCurrentPostId(),
		[]
	);

	const [ isOpen, setOpen ] = useState( false );
	const [ expiration, setExpiration ] = useState( String( settings.defaultExpiration ) );
	const [ oneTimeUse, setOneTimeUse ] = useState( false );
	const [ url, setUrl ] = useState( '' );
	const [ isBusy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ copied, setCopied ] = useState( false );

	const openModal = () => {
		setUrl( '' );
		setError( '' );
		setCopied( false );
		setOpen( true );
	};

	const copyLink = async () => {
		setBusy( true );
		setError( '' );

		try {
			const response = await apiFetch( {
				path: '/live-previews/v1/preview-links',
				method: 'POST',
				data: {
					post_id: postId,
					expiration: parseInt( expiration, 10 ),
					one_time_use: oneTimeUse,
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
		<PluginDocumentSettingPanel
			name="live-previews"
			title={ __( 'Live Previews', 'live-previews' ) }
		>
			<Button variant="secondary" onClick={ openModal } disabled={ ! postId }>
				{ __( 'Generate preview link', 'live-previews' ) }
			</Button>

			{ isOpen && (
				<Modal
					title={ __( 'Generate preview link', 'live-previews' ) }
					onRequestClose={ () => setOpen( false ) }
				>
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Anyone with this link will be able to preview the post.',
							'live-previews'
						) }
					</Notice>

					<SelectControl
						label={ __( 'Link expiration', 'live-previews' ) }
						value={ expiration }
						options={ expirationOptions }
						onChange={ setExpiration }
						__nextHasNoMarginBottom
					/>

					<CheckboxControl
						label={ __( 'One-time use', 'live-previews' ) }
						help={ __( 'The link will expire after one visit.', 'live-previews' ) }
						checked={ oneTimeUse }
						onChange={ setOneTimeUse }
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
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'live-previews', { render: GeneratePreviewLink } );
