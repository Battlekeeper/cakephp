<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP Project
 * @since         4.1.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Test\TestCase\Error\Debug;

use Cake\Error\Debug\ArrayItemNode;
use Cake\Error\Debug\ArrayNode;
use Cake\Error\Debug\ClassNode;
use Cake\Error\Debug\HtmlFormatter;
use Cake\Error\Debug\PropertyNode;
use Cake\Error\Debug\ReferenceNode;
use Cake\Error\Debug\ScalarNode;
use Cake\Error\Debug\SpecialNode;
use Cake\TestSuite\TestCase;
use DomDocument;

/**
 * HtmlFormatterTest
 */
class HtmlFormatterTest extends TestCase
{
    /**
     * Test dumping a graph that contains all possible nodes.
     */
    public function testDump(): void
    {
        $node = new ClassNode('MyObject', 1);
        $node->addProperty(new PropertyNode('stringProp', 'public', new ScalarNode('string', 'value')));
        $node->addProperty(new PropertyNode('intProp', 'protected', new ScalarNode('int', 1)));
        $node->addProperty(new PropertyNode('floatProp', 'protected', new ScalarNode('float', 1.1)));
        $node->addProperty(new PropertyNode('boolProp', 'protected', new ScalarNode('bool', true)));
        $node->addProperty(new PropertyNode('nullProp', 'private', new ScalarNode('null', null)));
        $arrayNode = new ArrayNode([
            new ArrayItemNode(new ScalarNode('string', ''), new SpecialNode('too much')),
            new ArrayItemNode(new ScalarNode('int', 1), new ReferenceNode('MyObject', 1)),
        ]);
        $node->addProperty(new PropertyNode('arrayProp', 'public', $arrayNode));

        $formatter = new HtmlFormatter();
        $result = $formatter->dump($node);

        // Check important classnames
        $this->assertStringContainsString('class="cake-debug-const"', $result);
        $this->assertStringContainsString('class="cake-debug-string"', $result);
        $this->assertStringContainsString('class="cake-debug-number"', $result);
        $this->assertStringContainsString('class="cake-debug-array-items"', $result);
        $this->assertStringContainsString('class="cake-debug-array-item"', $result);
        $this->assertStringContainsString('class="cake-debug-array"', $result);
        $this->assertStringContainsString('class="cake-debug-object"', $result);
        $this->assertStringContainsString('class="cake-debug-object-props"', $result);
        $this->assertStringContainsString('class="cake-debug-special"', $result);
        $this->assertStringContainsString('class="cake-debug-ref"', $result);

        // Check valid HTML
        $dom = new DomDocument();
        $dom->loadHtml($result);
        $this->assertGreaterThan(0, count($dom->childNodes));

        $expected = <<<TEXT
object(MyObject) id:1 {
  stringProp =&gt; &#039;value&#039;
  protected intProp =&gt; (int) 1
  protected floatProp =&gt; (float) 1.1
  protected boolProp =&gt; true
  private nullProp =&gt; null
  arrayProp =&gt; [
    &#039;&#039; =&gt; too much,
    (int) 1 =&gt; object(MyObject) id: 1 {},
  ]
}
TEXT;
        $this->assertStringContainsString($expected, strip_tags($result));
    }

    /**
     * Test that reset() clears the $outputHeader flag so the next dump() call
     * re-emits the CSS and JavaScript header.
     *
     * In worker mode the same PHP process handles many HTTP responses.
     * Without resetting this flag, only the very first response would contain the
     * debug stylesheet and JavaScript — all subsequent responses would lack it,
     * breaking the interactive debug output.
     */
    public function testReset(): void
    {
        // Start from a known clean state regardless of other test ordering.
        HtmlFormatter::reset();
        $formatter = new HtmlFormatter();

        // First dump() call should include the CSS/JS header.
        $firstDump = $formatter->dump(new ScalarNode('string', 'first'));
        $this->assertStringContainsString('<style>', $firstDump, 'First dump should include CSS header');

        // Second dump() in the same process/request should NOT repeat the header.
        $secondDump = $formatter->dump(new ScalarNode('string', 'second'));
        $this->assertStringNotContainsString('<style>', $secondDump, 'Second dump should not repeat CSS header');

        // After reset() (simulating a new request in worker mode) the header must
        // be emitted again so the next HTTP response is self-contained.
        HtmlFormatter::reset();
        $afterReset = $formatter->dump(new ScalarNode('string', 'after-reset'));
        $this->assertStringContainsString('<style>', $afterReset, 'Dump after reset() should include CSS header again');
    }
}
