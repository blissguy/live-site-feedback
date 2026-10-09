<?php
/**
 * Signs the short-lived tokens that let WordPress users comment as themselves.
 *
 * The browser asks WordPress for a token, WordPress signs one with the private
 * key created during setup, and MarkUp.io trades it for a session. The API key
 * never leaves the server and the visitor never signs in to anything.
 *
 * @package MBM_Live_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mints identity tokens for the current WordPress user.
 */
class MBM_LF_Tokens {

	/**
	 * How long a token stays valid, in seconds.
	 *
	 * Deliberately short: it is only ever used immediately, to be exchanged for
	 * a MarkUp.io session.
	 */
	const LIFETIME = 300;

	/**
	 * The roles MarkUp.io understands.
	 *
	 * Anything it does not recognise falls back to read-only, silently — so an
	 * unrecognised value must never reach the token.
	 *
	 * @var string[]
	 */
	const ROLES = [ 'project:owner', 'project:contributor', 'project:viewer' ];

	/**
	 * Whether we are able to sign tokens at all.
	 *
	 * @return bool
	 */
	public function can_sign() {
		if ( ! function_exists( 'openssl_sign' ) ) {
			return false;
		}

		return '' !== MBM_LF_Credentials::private_key()
			&& MBM_LF_Credentials::has( 'iss' )
			&& MBM_LF_Credentials::has( 'aud' );
	}

	/**
	 * Sign a token for a user.
	 *
	 * @param WP_User $user User to sign for.
	 * @return string|WP_Error The token.
	 */
	public function mint( WP_User $user ) {
		if ( ! $this->can_sign() ) {
			return new WP_Error(
				'mbm_lf_cannot_sign',
				__( 'This site isn\'t set up to sign people in yet.', 'mbm-live-feedback' )
			);
		}

		$key = openssl_pkey_get_private( MBM_LF_Credentials::private_key() );

		if ( false === $key ) {
			return new WP_Error(
				'mbm_lf_bad_signing_key',
				__( 'The signing key couldn\'t be read. Press Check and repair setup.', 'mbm-live-feedback' )
			);
		}

		$now = time();

		$claims = [
			'iss'        => MBM_LF_Credentials::get( 'iss' ),
			'aud'        => MBM_LF_Credentials::get( 'aud' ),
			'sub'        => $this->subject( $user->ID ),
			'iat'        => $now,
			'exp'        => $now + self::LIFETIME,
			'jti'        => wp_generate_uuid4(),
			'name'       => $user->display_name,
			'email'      => $user->user_email,
			'avatarUrl'  => get_avatar_url( $user->ID, [ 'size' => 96 ] ),
			'markupRole' => $this->role_for( $user ),
		];

		/**
		 * Filters the claims placed in a feedback token.
		 *
		 * @param array   $claims The claims.
		 * @param WP_User $user   The user the token is for.
		 */
		$claims = (array) apply_filters( 'mbm_lf_token_claims', $claims, $user );

		$header = [
			'alg' => 'RS256',
			'typ' => 'JWT',
		];

		$input = $this->b64( wp_json_encode( $header ) ) . '.' . $this->b64( wp_json_encode( $claims ) );

		$signature = '';

		if ( ! openssl_sign( $input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error(
				'mbm_lf_sign_failed',
				__( 'This server couldn\'t confirm who you are. Your host may be missing the security functions WordPress needs.', 'mbm-live-feedback' )
			);
		}

		return $input . '.' . $this->b64( $signature );
	}

	/**
	 * A stable, meaningless identifier for a user.
	 *
	 * MarkUp.io needs something consistent to recognise a returning commenter,
	 * but it has no need for the actual WordPress user ID — so it gets a hash
	 * that is unique to this site and cannot be traced back.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public function subject( $user_id ) {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : ABSPATH );

		return 'wp_' . hash_hmac( 'sha256', 'user:' . (int) $user_id, $salt );
	}

	/**
	 * Which MarkUp.io role to claim for a user.
	 *
	 * MarkUp.io honors this now. Until some point after 2026-10-03 it ignored
	 * the claim and gave everyone project:contributor (tested 2026-07-29).
	 * Re-tested 2026-10-09 against the live installation: owner, contributor
	 * and viewer each come back exactly as sent, an omitted claim gives
	 * contributor, and anything else is refused with a 400, so sign-in fails.
	 *
	 * That made the role decide whether someone can comment at all. See
	 * default_role_for().
	 *
	 * @param WP_User $user User to check.
	 * @return string One of self::ROLES.
	 */
	public function role_for( WP_User $user ) {
		$role = $this->default_role_for( $user );

		/**
		 * Filters the MarkUp.io role given to a user.
		 *
		 * @param string  $role One of project:owner, project:contributor, project:viewer.
		 * @param WP_User $user The user.
		 */
		$role = (string) apply_filters( 'mbm_lf_user_role', $role, $user );

		// Never let an unrecognized value through: MarkUp.io refuses it outright,
		// and the person can't sign in to the feedback bar at all.
		return in_array( $role, self::ROLES, true ) ? $role : 'project:contributor';
	}

	/**
	 * The role a user gets when nothing has been configured.
	 *
	 * Someone trusted to delete other people's posts is trusted to delete other
	 * people's comments. Everybody else comments as a contributor.
	 *
	 * Never project:viewer by default. Only people the visibility setting lets
	 * see the bar ever get a token, so everyone who reaches this was chosen to
	 * give feedback. Viewer used to be the fallback for anyone who couldn't edit
	 * posts, and once MarkUp.io started honoring the claim it quietly turned
	 * reviewers with member accounts (learners, on learnwithvelo.com) into
	 * read-only visitors. A site that genuinely wants read-only reviewers can
	 * still say so through the mbm_lf_user_role filter.
	 *
	 * @param WP_User $user User to check.
	 * @return string
	 */
	public function default_role_for( WP_User $user ) {
		if ( user_can( $user, 'delete_others_posts' ) ) {
			return 'project:owner';
		}

		return 'project:contributor';
	}

	/**
	 * Base64url encode, without padding.
	 *
	 * @param string $data Raw data.
	 * @return string
	 */
	private function b64( $data ) {
		return rtrim( strtr( base64_encode( (string) $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
