<?php
/**
 * Solr Search Object Options Test
 *
 * PHP version 7
 *
 * Copyright (C) Villanova University 2021.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Tests
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:testing:unit_tests Wiki
 */
namespace VuFindTest\Search\Solr;

use VuFind\Config\PluginManager;
use VuFind\Search\Solr\Options;

/**
 * Solr Search Object Options Test
 *
 * @category VuFind
 * @package  Tests
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:testing:unit_tests Wiki
 */
class OptionsTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Get mock configuration plugin manager
     *
     * @return PluginManager
     */
    protected function getMockConfigManager()
    {
        return $this->getMockBuilder(PluginManager::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * Get Options object
     *
     * @param PluginManager $configManager Config manager for Options object (null
     * for new mock)
     *
     * @return Options
     */
    protected function getOptions($configManager = null)
    {
        return new Options($configManager ?? $this->getMockConfigManager());
    }

    /**
     * Test that correct search class ID is reported
     *
     * @return void
     */
    public function testGetSearchClassId()
    {
        $this->assertEquals('Solr', $this->getOptions()->getSearchClassId());
    }
}
