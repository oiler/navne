// assets/js/sidebar/hooks/useSuggestions.js
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

export default function useSuggestions( postId ) {
	const [ jobStatus, setJobStatus ]     = useState( 'idle' );
	const [ suggestions, setSuggestions ] = useState( [] );
	const [ isLoading, setIsLoading ]     = useState( false );
	const [ mode, setMode ]               = useState( 'suggest' );
	const pollRef                         = useRef( null );
	const wasSaving                       = useRef( false );

	const isSaving = useSelect( ( select ) =>
		select( 'core/editor' ).isSavingPost()
	);

	const fetchSuggestions = useCallback( async () => {
		try {
			const data = await apiFetch( { path: `/navne/v1/suggestions/${ postId }` } );
			setJobStatus( data.job_status );
			setSuggestions( data.suggestions );
			setMode( data.mode || 'suggest' );
			return data.job_status;
		} catch {
			setJobStatus( 'failed' );
			return 'failed';
		}
	}, [ postId ] );

	const stopPolling = useCallback( () => {
		if ( pollRef.current ) {
			clearInterval( pollRef.current );
			pollRef.current = null;
		}
		setIsLoading( false );
	}, [] );

	const startPolling = useCallback( () => {
		if ( pollRef.current ) return;
		setIsLoading( true );
		// Fetch immediately, then poll every 3 seconds.
		fetchSuggestions().then( ( status ) => {
			if ( status === 'complete' || status === 'failed' ) {
				stopPolling();
				return;
			}
			pollRef.current = setInterval( async () => {
				const s = await fetchSuggestions();
				if ( s === 'complete' || s === 'failed' ) {
					stopPolling();
				}
			}, 3000 );
		} );
	}, [ fetchSuggestions, stopPolling ] );

	// After a save: auto-poll in Suggest/YOLO; refresh state only in Safe.
	useEffect( () => {
		if ( wasSaving.current && ! isSaving ) {
			if ( mode !== 'safe' ) {
				startPolling();
			} else {
				fetchSuggestions();
			}
		}
		wasSaving.current = isSaving;
	}, [ isSaving, mode, startPolling, fetchSuggestions ] );

	// Load existing suggestions on mount.
	useEffect( () => {
		fetchSuggestions();
		return stopPolling;
	}, [ fetchSuggestions, stopPolling ] );

	const approve = useCallback( async ( id ) => {
		setSuggestions( ( prev ) => {
			return prev.map( ( s ) => ( s.id === id ? { ...s, status: 'approved' } : s ) );
		} );
		try {
			await apiFetch( {
				path:   `/navne/v1/suggestions/${ postId }/approve`,
				method: 'POST',
				data:   { id },
			} );
		} catch {
			// Rollback optimistic update on failure.
			setSuggestions( ( prev ) =>
				prev.map( ( s ) => ( s.id === id && s.status === 'approved' ? { ...s, status: 'pending' } : s ) )
			);
		}
	}, [ postId ] );

	const dismiss = useCallback( async ( id ) => {
		setSuggestions( ( prev ) => {
			return prev.map( ( s ) => ( s.id === id ? { ...s, status: 'dismissed' } : s ) );
		} );
		try {
			await apiFetch( {
				path:   `/navne/v1/suggestions/${ postId }/dismiss`,
				method: 'POST',
				data:   { id },
			} );
		} catch {
			// Rollback optimistic update on failure.
			setSuggestions( ( prev ) =>
				prev.map( ( s ) => ( s.id === id && s.status === 'dismissed' ? { ...s, status: 'pending' } : s ) )
			);
		}
	}, [ postId ] );

	const retry = useCallback( async () => {
		stopPolling();
		await apiFetch( {
			path:   `/navne/v1/suggestions/${ postId }/retry`,
			method: 'POST',
		} );
		setJobStatus( 'queued' );
		startPolling();
	}, [ postId, stopPolling, startPolling ] );

	return { jobStatus, suggestions, isLoading, mode, approve, dismiss, retry };
}
