<?php

namespace Modules\Notifications\Messaging;

use RuntimeException;

/**
 * The message can never be processed (not JSON, wrong event type, missing
 * fields). Retrying cannot help, so it goes straight to the dead letter queue.
 */
class InvalidMessageException extends RuntimeException {}
