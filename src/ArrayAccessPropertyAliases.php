<?php

/**
 * Adds the ability to access ArrayAccess indexes through object properties.
 *
 * WARNING: In its current form, this trait is only designed to be used from a
 * class where it or an extended parent implements ArrayAccess, but NOT any of
 * the magic methods: __set, __unset, __isset, __get. All access to properties
 * on the using class - except for public variables - are proxied through the
 * ArrayAccess methods: offsetSet, offsetUnset, offsetExists, offsetGet. This
 * trait will break any class that uses the magic methods for its own use!
 *
 * USAGE:
 *
 * <code>
 *  /**
 *   * In this example, ClassImplementingArrayAccess has 'bool_flag' and '!foo.bar!' indexes
 *   * in use through ArrayAccess. $bool_flag is declared without an explicit alias since the
 *   * name is safe (<code>$object['bool_flag'] === $object->bool_flag</code>). The '!foo.bar!'
 *   * index is aliased to $foo_bar (<code>$object['!foo.bar!'] === $object->foo_bar</code>).
 *   *
 *   * {@}property bool $bool_flag
 *   * {@}property AnotherClass $foo_bar array-access="!foo.bar!"
 *   *\/
 *  class MyClass extends ClassImplementingArrayAccess {
 *      use ArrayAccessPropertyAliases;
 *
 *      public function __construct() {
 *          $this->processArrayAccessPropertyAliases();
 *      }
 *  }
 *
 *  $object = new MyClass();
 *
 *  // get, set, isset and unset all work
 *  unset($object->bool_flag);
 *  $object->bool_flag = true;
 *  $flagSet           = isset($object->bool_flag);
 *  $flag              = $object->bool_flag;
 *
 *  // the IDE now knows $object->foo_bar is of type AnotherClass
 *  $object->foo_bar->doSomething();
 * </code>
 *
 * ArrayAccess is a great concept for PHP. Unfortunately, there is a drawback to
 * using it instead of the magic methods (__set, __unset, __isset, __get): IDE
 * code completion. Editors like PhpStorm and Eclipse are able to parse {@}property
 * PHPDoc tags from class declarations to indicate the availability of additional
 * object properties that are handled by the magic methods. There is currently no
 * such tooling available for ArrayAccess, so this trait permits a workaround that
 * allows proxying access to object properties to ArrayAccess indexes.
 *
 * By simply adding <code>use ArrayAccessPropertyAliases</code> to a class, the
 * contents of the ArrayAccess container can thereafter be accessed using object
 * properties. Essentially, <code>$object['foo_bar'];</code> also becomes available
 * as <code>$object->foo_bar</code>. This works for both read and write access. By
 * type-hinting to the IDE with <code>{@}property AnotherClass $foo_bar</code> in the
 * class's PHPDoc, it is now able to provide code completion functionality for the
 * 'foo_bar' entry. For example, typing <code>$object-></code> will show 'foo_bar'
 * as an available property. More importantly, typing <code>$object->foo_bar-></code>
 * provides code completion based on the variable type-hint - in the above example,
 * $foo_bar is typed to the class 'AnotherClass', so that class's properties and
 * methods will now be completed. Much better than having to type-hint every instance
 * of a variable being extracted from an ArrayAccess container!
 *
 * What about ArrayAccess indexes that are named such that they are not valid object
 * property names? For example, '!foo.bar!' can be used as an index in an ArrayAccess
 * object, but <code>$object->!foo.bar!->toString()</code> is not valid. Enter property
 * aliasing. In order to enable the use of aliases, it is required (don't forget!) for the
 * class using the trait to call <code>$this->processArrayAccessPropertyAliases();</code>
 * from the constructor - it should be the very first thing done, before calling a
 * parent constructor or accessing any properties or ArrayAccess indexes.
 *
 * Once aliasing is available, when declaring a {@}property entry on the class it is now
 * possible to make it an alias for another index held within the ArrayAccess container.
 * Just add <code>array-access="index-name"</code> immediately after the property name,
 * and now the object property points to that index. For example, by adding
 * <code>{@}property AnotherClass $foo_bar array-access="!foo.bar!"</code> to the class,
 * <code>$object->foo_bar</code> is the equivalent of <code>$object['!foo.bar!'];</code>.
 *
 * There is a limitation imposed by using an alias in this way. If the ArrayAccess container
 * has both 'foo.bar' and 'foo_bar' as indexes, there would be a conflict in setting a
 * $foo_bar property for anything other than the 'foo_bar' index. If a class was declared
 * with <code>{@}property AnotherClass $foo_bar array-access="foo.bar"</code> and then an
 * access was attempted with <code>$object->foo_bar</code>, it would be dangerous to
 * make the assumption that this should map to either the existing 'foo_bar' index or
 * to the aliased 'foo.bar'. For this reason, all accesses are verified for conflicts.
 * In the event that a property name is aliased to a conflicting ArrayAccess index,
 * an Exception is thrown indicating that the property must be renamed. In the situation
 * above, it becomes necessary to rename the $foo_bar property to something else so as
 * not to conflict with the existing 'foo_bar' index within the ArrayAccess container.
 *
 * @version 1.0
 * @license http://unlicense.org/UNLICENSE
 * @link https://github.com/frickenate/snippets/blob/master/src/ArrayAccessPropertyAliases.php
 */
trait ArrayAccessPropertyAliases {
    /**
     * @var array Mapping of "property name" => "array access index" aliases
     */
    protected $arrayAccessAliases = [];

    /**
     * Parses {@}property declarations from the using class to map property aliases.
     *
     * Format: <code>* {@}property $var_name array-access=(['"])alias-name\1</code>
     */
    public function processArrayAccessPropertyAliases() {
        $this->arrayAccessAliases = preg_match_all(
            '/^\s*+\*\s*+@property[^$]++\$(\S++)\s++array-access\s*+=\s*+(["\'])((?:(?!\2).)++)\2/m',
            (new \ReflectionClass($this))->getDocComment(), $aliases
        ) ? array_combine($aliases[1], $aliases[3]) : [];
    }

    // handles access to object properties
    public function __set($id, $value) { $this->doPropertyAccess('offsetSet', $id, $value); }
    public function __unset($id) { $this->doPropertyAccess('offsetUnset', $id); }
    public function __isset($id) { return $this->doPropertyAccess('offsetExists', $id); }
    public function __get($id) { return $this->doPropertyAccess('offsetGet', $id); }

    // handles access to ArrayAccess indexes
    public function offsetSet($id, $value) { $this->doArrayAccess('offsetSet', $id, $value); }
    public function offsetUnset($id) { $this->doArrayAccess('offsetUnset', $id); }
    public function offsetExists($id) { return $this->doArrayAccess('offsetExists', $id); }
    public function offsetGet($id) { return $this->doArrayAccess('offsetGet', $id); }

    /**
     * Internal method that handles access to object properties.
     *
     * @param string $method One of [offsetSet, offsetUnset, offsetExists, offsetGet]
     * @param string $name The object property name being accessed
     * @param mixed $value When $method is 'offsetSet', the value being set
     *
     * @return mixed The result of the parent call to $method
     * @throws \Exception When a collision conflict is detected indicating a property alias needs to be renamed
     */
    protected function doPropertyAccess($method, $name, $value = null) {
        $actualId = isset($this->arrayAccessAliases[$name]) ? $this->arrayAccessAliases[$name] : $name;
        if ($actualId !== $name && parent::offsetExists($name)) {
            throw new \Exception("Application property '{$name} conflicts with another name - please rename it");
        }
        return parent::$method($actualId, $value);
    }

    /**
     * Internal method that handles access to ArrayAccess indexes.
     *
     * @param string $method One of [offsetSet, offsetUnset, offsetExists, offsetGet]
     * @param string $name The ArrayAccess index being accessed
     * @param mixed $value When $method is 'offsetSet', the value being set
     *
     * @return mixed The result of the parent call to $method
     * @throws \Exception When a collision conflict is detected indicating a property alias needs to be renamed
     */
    protected function doArrayAccess($method, $name, $value = null) {
        if (isset($this->arrayAccessAliases[$name]) && $this->arrayAccessAliases[$name] !== $name) {
            throw new \Exception("Application property '{$name} conflicts with another name - please rename it");
        }
        return parent::$method($name, $value);
    }
}
