<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\IndexNow\Exception;

/**
 * Marker for every exception this library throws on purpose.
 *
 * Transport failures are not exceptions here: they come back as a Result,
 * because a queue has to decide about them, not a stack unwinder.
 */
interface IndexNowException extends \Throwable {}
