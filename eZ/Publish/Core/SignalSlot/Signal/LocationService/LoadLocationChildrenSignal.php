<?php
/**
 * LoadLocationChildrenSignal class
 *
 * @copyright Copyright (C) 1999-2012 eZ Systems AS. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 * @version //autogentag//
 */

namespace eZ\Publish\Core\SignalSlot\Signal\LocationService;
use eZ\Publish\Core\SignalSlot\Signal;

/**
 * LoadLocationChildrenSignal class
 * @package eZ\Publish\Core\SignalSlot\Signal\LocationService
 */
class LoadLocationChildrenSignal extends Signal
{
    /**
     * LocationId
     *
     * @var mixed
     */
    public $locationId;

    /**
     * Offset
     *
     * @var mixed
     */
    public $offset;

    /**
     * Limit
     *
     * @var mixed
     */
    public $limit;

}
