<?php
namespace Imi;

use Imi\Util\Imi;
use Imi\Util\ArrayData;

abstract class Config
{
	/**
	 * 配置数组
	 * @var ArrayData[]
	 */
	private static $configs = [];

	/**
	 * 增加配置
	 * @param string $name
	 * @param array $config
	 * @return boolean
	 */
	public static function addConfig($name, array $config)
	{
		if(isset(static::$configs[$name]))
		{
			return false;
		}
		static::$configs[$name] = new ArrayData($config);
		if(static::$configs[$name]->exists('configs'))
		{
			static::load($name, static::$configs[$name]->get('configs', []));
		}
		return true;
	}

	/**
	 * 加载配置列表
	 * @param array $configList
	 * @return void
	 */
	public static function load($name, array $configList)
	{
		foreach($configList as $alias => $fileName)
		{
			static::set($name . '.' . $alias, include $fileName);
		}
	}
	
	/**
	 * 设置配置
	 * @param string $name
	 * @param array $config
	 * @return boolean
	 */
	public static function setConfig($name, array $config)
	{
		static::$configs[$name] = new ArrayData($config);
	}

	/**
	 * 移除配置项
	 * @param string $name
	 * @return boolean
	 */
	public function removeConfig($name)
	{
		if(isset(static::$configs[$name]))
		{
			unset(static::$configs[$name]);
			return true;
		}
		else
		{
			return false;
		}
	}

	/**
	 * 设置配置值
	 * @param string $name
	 * @param mixed $value
	 * @return boolean
	 */
	public static function set(string $name, $value)
	{
		$names = Imi::parseDotRule($name);
		if (isset($names[0]))
		{
			$first = array_shift($names);
			if(isset(static::$configs[$first]))
			{
				return static::$configs[$first]->setVal($names, $value);
			}
			else
			{
				return false;
			}
		}
		else
		{
			return false;
		}
	}

	/**
	 * 获取配置值
	 * @param string $name
	 * @param mixed $default
	 * @return mixed
	 */
	public static function get(string $name, $default = null)
	{
		$names = Imi::parseDotRule($name);
		if (isset($names[0]))
		{
			$first = array_shift($names);
			if(isset(static::$configs[$first]))
			{
				return static::$configs[$first]->get($names, $default);
			}
			else
			{
				return $default;
			}
		}
		else
		{
			return $default;
		}
	}

	/**
	 * 配置值是否存在
	 * @param string $name
	 * @return boolean
	 */
	public static function has(string $name)
	{
		$names = Imi::parseDotRule($name);
		if (isset($names[0]))
		{
			$first = array_shift($names);
			if(isset(static::$configs[$first]))
			{
				return null !== static::$configs[$first]->get($names, null);
			}
			else
			{
				return false;
			}
		}
		else
		{
			return false;
		}
	}

	/**
	 * 获取所有别名
	 * @return array
	 */
	public static function getAlias()
	{
		return array_keys(static::$configs);
	}

	/**
	 * 清空所有配置项
	 * @return void
	 */
	public static function clear()
	{
		static::$configs = [];
	}
}