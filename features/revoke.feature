Feature: Preview links can be revoked from the command line

	Revoking leaves the tombstone the preview gate reads, so a visitor opening
	a revoked link is told why it stopped working rather than seeing a bare
	404. `--all` is the incident-response lever when a URL leaks.

	Background:
		Given a WP installation with the Live Previews plugin

	Scenario: A link or --all must be given
		When I run `wp post create --post_status=draft --post_title="A draft" --porcelain`
		And save STDOUT as {POST_ID}
		When I try `wp live-previews revoke {POST_ID}`
		Then STDERR should be:
			"""
			Error: Specify the link to revoke (a token hint or full id), or --all.
			"""

	Scenario: A link and --all cannot be combined
		When I run `wp post create --post_status=draft --post_title="A draft" --porcelain`
		And save STDOUT as {POST_ID}
		When I try `wp live-previews revoke {POST_ID} abcd --all`
		Then STDERR should be:
			"""
			Error: Specify either a link or --all, not both.
			"""

	Scenario: Error when nothing matches
		When I run `wp post create --post_status=draft --post_title="A draft" --porcelain`
		And save STDOUT as {POST_ID}
		When I try `wp live-previews revoke {POST_ID} zzzz`
		Then STDERR should be:
			"""
			Error: No preview link matches "zzzz".
			"""

	Scenario: Revoke a link by the token hint the list shows
		When I run `wp post create --post_status=draft --post_title="A draft" --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp live-previews create {POST_ID} --porcelain`
		And I run `wp live-previews list {POST_ID} --field=token_hint`
		And save STDOUT as {HINT}
		When I run `wp live-previews revoke {POST_ID} {HINT}`
		Then STDOUT should be:
			"""
			Success: Revoked 1 preview link.
			"""
		When I run `wp live-previews list {POST_ID} --format=count`
		Then STDOUT should be:
			"""
			0
			"""

	Scenario: Revoke every live link on the post at once
		When I run `wp post create --post_status=draft --post_title="A draft" --porcelain`
		And save STDOUT as {POST_ID}
		And I run `wp live-previews create {POST_ID} --porcelain`
		And I run `wp live-previews create {POST_ID} --porcelain`
		When I run `wp live-previews revoke {POST_ID} --all`
		Then STDOUT should be:
			"""
			Success: Revoked 2 preview links.
			"""
		When I run `wp live-previews list {POST_ID} --format=count`
		Then STDOUT should be:
			"""
			0
			"""
