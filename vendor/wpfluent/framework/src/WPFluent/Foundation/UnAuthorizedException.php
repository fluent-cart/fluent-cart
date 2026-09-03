<?php

namespace FluentCart\Framework\Foundation;

use FluentCart\Framework\Foundation\Exceptions\UnauthorizedHttpException;

/**
 * @deprecated 2.12.0 Use \FluentCart\Framework\Foundation\Exceptions\UnauthorizedHttpException
 *             instead. This shim retains the non-PSR class name (note the
 *             second capital A) so existing throw/catch sites keep working;
 *             it produces the same 401 response via Route.php's HttpException
 *             branch.
 */
class UnAuthorizedException extends UnauthorizedHttpException
{
    //
}
