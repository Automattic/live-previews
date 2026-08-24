<?php

namespace Automattic\LivePreviews;

/**
 * The real wall clock, used in production.
 */
final class SystemClock implements Clock {
	public function now(): int {
		return time();
	}
}
