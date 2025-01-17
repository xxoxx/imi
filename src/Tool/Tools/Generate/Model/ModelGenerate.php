<?php

namespace Imi\Tool\Tools\Generate\Model;

use Imi\Config;
use Imi\Db\Db;
use Imi\Db\Query\Interfaces\IQuery;
use Imi\Db\Util\SqlUtil;
use Imi\Model\Model;
use Imi\Tool\Annotation\Arg;
use Imi\Tool\Annotation\Operation;
use Imi\Tool\Annotation\Tool;
use Imi\Tool\ArgType;
use Imi\Util\File;
use Imi\Util\Imi;
use Imi\Util\Text;

/**
 * @Tool("generate")
 */
class ModelGenerate
{
    /**
     * 生成数据库中所有表的模型文件，如果设置了`include`或`exclude`，则按照相应规则过滤表。
     *
     * @Operation("model")
     *
     * @Arg(name="namespace", type=ArgType::STRING, required=true, comments="生成的Model所在命名空间")
     * @Arg(name="baseClass", type=ArgType::STRING, default="Imi\Model\Model", comments="生成的Model所继承的基类,默认\Imi\Model\Model,可选")
     * @Arg(name="database", type=ArgType::STRING, comments="数据库名，不传则取连接池默认配置的库名")
     * @Arg(name="poolName", type=ArgType::STRING, comments="连接池名称，不传则取默认连接池")
     * @Arg(name="prefix", type=ArgType::ARRAY, default={}, comments="传值则去除该表前缀，以半角逗号分隔多个前缀")
     * @Arg(name="include", type=ArgType::ARRAY, default={}, comments="要包含的表名，以半角逗号分隔")
     * @Arg(name="exclude", type=ArgType::ARRAY, default={}, comments="要排除的表名，以半角逗号分隔")
     * @Arg(name="override", type=ArgType::STRING, default=false, comments="是否覆盖已存在的文件，请慎重！true-全覆盖;false-不覆盖;base-覆盖基类;model-覆盖模型类;默认缺省状态为false")
     * @Arg(name="config", type=ArgType::STRING, default=true, comments="配置文件。true-项目配置；false-忽略配置；php配置文件名-使用该配置文件。默认为true")
     * @Arg(name="basePath", type=ArgType::STRING, default=null, comments="指定命名空间对应的基准路径，可选")
     * @Arg(name="entity", type=ArgType::BOOLEAN, default=true, comments="序列化时是否使用驼峰命名(true or false),默认true,可选")
     * @Arg(name="sqlSingleLine", type=ArgType::BOOLEAN, default=false, comments="生成的SQL为单行,默认false,可选")
     *
     * @param string      $namespace
     * @param string      $baseClass
     * @param string|null $database
     * @param string|null $poolName
     * @param array       $prefix
     * @param array       $include
     * @param array       $exclude
     * @param string|bool $override
     * @param string|bool $config
     * @param string|null $basePath
     * @param bool        $entity
     * @param bool        $sqlSingleLine
     *
     * @return void
     */
    public function generate($namespace, $baseClass, $database, $poolName, $prefix, $include, $exclude, $override, $config, $basePath, $entity, $sqlSingleLine)
    {
        $override = (string) $override;
        switch ($override)
        {
            case 'base':
                break;
            case 'model':
                break;
            default:
                $override = (bool) json_decode($override);
        }
        if (\in_array($config, ['true', 'false']))
        {
            $config = (bool) json_decode($config);
        }
        if (true === $config)
        {
            $configData = Config::get('@app.tools.generate/model');
        }
        elseif (\is_string($config))
        {
            $configData = include $config;
            $configData = $configData['tools']['generate/model'];
        }
        else
        {
            $configData = null;
        }
        $query = Db::query($poolName);
        // 数据库
        if (null === $database)
        {
            $database = $query->execute('select database()')->getScalar();
        }
        // 表
        $list = $query->tableRaw('information_schema.TABLES')
                      ->where('TABLE_SCHEMA', '=', $database)
                      ->whereIn('TABLE_TYPE', [
                          'BASE TABLE',
                          'VIEW',
                      ])
                      ->field('TABLE_NAME', 'TABLE_TYPE', 'TABLE_COMMENT')
                      ->select()
                      ->getArray();
        // model保存路径
        if (null === $basePath)
        {
            $modelPath = Imi::getNamespacePath($namespace);
        }
        else
        {
            $modelPath = $basePath;
        }
        if (null === $modelPath)
        {
            echo 'Namespace ', $namespace, ' cannot found', \PHP_EOL;

            return;
        }
        if (empty($baseClass) || !class_exists($baseClass))
        {
            echo 'BaseClass ', $baseClass, ' cannot found', \PHP_EOL;

            return;
        }
        if (Model::class !== $baseClass && !is_subclass_of($baseClass, Model::class))
        {
            echo 'BaseClass ', $baseClass, ' not extends ', Model::class, \PHP_EOL;

            return;
        }
        echo 'modelPath: ', $modelPath, \PHP_EOL;
        echo 'baseClass: ', $baseClass, \PHP_EOL;
        File::createDir($modelPath);
        $baseModelPath = $modelPath . '/Base';
        File::createDir($baseModelPath);
        foreach ($list as $item)
        {
            $table = $item['TABLE_NAME'];
            if (!$this->checkTable($table, $include, $exclude))
            {
                // 不符合$include和$exclude
                continue;
            }
            $className = $this->getClassName($table, $prefix);
            if (isset($configData['relation'][$table]))
            {
                $configItem = $configData['relation'][$table];
                $modelNamespace = $configItem['namespace'] ?? $namespace;
                $path = Imi::getNamespacePath($modelNamespace);
                if (null === $path)
                {
                    echo 'Namespace ', $modelNamespace, ' cannot found', \PHP_EOL;

                    return;
                }
                File::createDir($path);
                $basePath = $path . '/Base';
                File::createDir($basePath);
                $fileName = File::path($path, $className . '.php');
                $withRecords = $configItem['withRecords'] ?? false;
            }
            else
            {
                $hasResult = false;
                foreach ($configData['namespace'] ?? [] as $namespaceName => $namespaceItem)
                {
                    if (\in_array($table, $namespaceItem['tables'] ?? []))
                    {
                        $modelNamespace = $namespaceName;
                        $path = Imi::getNamespacePath($modelNamespace);
                        if (null === $path)
                        {
                            echo 'Namespace ', $modelNamespace, ' cannot found', \PHP_EOL;

                            return;
                        }
                        File::createDir($path);
                        $basePath = $path . '/Base';
                        File::createDir($basePath);
                        $fileName = File::path($path, $className . '.php');
                        $hasResult = true;
                        $withRecords = \in_array($table, $namespaceItem['withRecords'] ?? []);
                        break;
                    }
                }
                if (!$hasResult)
                {
                    $modelNamespace = $namespace;
                    $fileName = File::path($modelPath, $className . '.php');
                    $basePath = $baseModelPath;
                    $withRecords = false;
                }
            }
            // @phpstan-ignore-next-line
            if (false === $override && is_file($fileName))
            {
                // 不覆盖
                echo 'Skip ', $table, '...', \PHP_EOL;
                continue;
            }
            $ddl = $this->getDDL($query, $table);
            // @phpstan-ignore-next-line
            if ($withRecords)
            {
                $dataList = $query->from($table)->select()->getArray();
                $ddl .= ';' . \PHP_EOL . SqlUtil::buildInsertSql($table, $dataList);
            }
            if ($sqlSingleLine)
            {
                $ddl = str_replace(\PHP_EOL, ' ', $ddl);
            }
            $data = [
                // @phpstan-ignore-next-line
                'namespace'     => $modelNamespace,
                'baseClassName' => $baseClass,
                'className'     => $className,
                'table'         => [
                    'name'  => $table,
                    'id'    => [],
                ],
                'fields'        => [],
                'entity'        => $entity,
                'poolName'      => $poolName,
                'ddl'           => $ddl,
                'tableComment'  => '' === $item['TABLE_COMMENT'] ? $table : $item['TABLE_COMMENT'],
            ];
            $fields = $query->execute(sprintf('show full columns from `%s`.`%s`', $database, $table))->getArray();
            $this->parseFields($fields, $data, 'VIEW' === $item['TABLE_TYPE']);

            $baseFileName = File::path($basePath, $className . 'Base.php');
            if (!is_file($baseFileName) || true === $override || 'base' === $override)
            {
                echo 'Generating ', $table, ' BaseClass...', \PHP_EOL;
                $baseContent = $this->renderTemplate('base-template', $data);
                File::putContents($baseFileName, $baseContent);
            }

            // @phpstan-ignore-next-line
            if (!is_file($fileName) || true === $override || 'model' === $override)
            {
                echo 'Generating ', $table, ' Class...', \PHP_EOL;
                $content = $this->renderTemplate('template', $data);
                // @phpstan-ignore-next-line
                File::putContents($fileName, $content);
            }
        }
        echo 'Complete', \PHP_EOL;
    }

    /**
     * 检查表是否生成.
     *
     * @param string $table
     * @param array  $include
     * @param array  $exclude
     *
     * @return bool
     */
    private function checkTable($table, $include, $exclude)
    {
        if (\in_array($table, $exclude))
        {
            return false;
        }

        return !isset($include[0]) || \in_array($table, $include);
    }

    /**
     * 表名转短类名.
     *
     * @param string $table
     * @param array  $prefixs
     *
     * @return string
     */
    private function getClassName($table, $prefixs)
    {
        foreach ($prefixs as $prefix)
        {
            $prefixLen = \strlen($prefix);
            if (substr($table, 0, $prefixLen) === $prefix)
            {
                $table = substr($table, $prefixLen);
                break;
            }
        }

        return Text::toPascalName($table);
    }

    /**
     * 处理字段信息.
     *
     * @param array $fields
     * @param array $data
     * @param bool  $isView
     *
     * @return void
     */
    private function parseFields($fields, &$data, $isView)
    {
        $idCount = 0;
        foreach ($fields as $i => $field)
        {
            $this->parseFieldType($field['Type'], $typeName, $length, $accuracy);
            if ($isView && 0 === $i)
            {
                $isPk = true;
            }
            else
            {
                $isPk = 'PRI' === $field['Key'];
            }
            $data['fields'][] = [
                'name'              => $field['Field'],
                'varName'           => Text::toCamelName($field['Field']),
                'type'              => $typeName,
                'phpType'           => $this->dbFieldTypeToPhp($typeName),
                'length'            => $length,
                'accuracy'          => $accuracy,
                'nullable'          => 'YES' === $field['Null'],
                'default'           => $field['Default'],
                'isPrimaryKey'      => $isPk,
                'primaryKeyIndex'   => $isPk ? $idCount : -1,
                'isAutoIncrement'   => false !== strpos($field['Extra'], 'auto_increment'),
                'comment'           => $field['Comment'],
            ];
            if ($isPk)
            {
                $data['table']['id'][] = $field['Field'];
                ++$idCount;
            }
        }
    }

    /**
     * 处理类似varchar(32)和decimal(10,2)格式的字段类型.
     *
     * @param string $text
     * @param string $typeName
     * @param int    $length
     * @param int    $accuracy
     *
     * @return bool
     */
    public function parseFieldType($text, &$typeName, &$length, &$accuracy)
    {
        if (preg_match('/([^(]+)(\((\d+)(,(\d+))?\))?/', $text, $match))
        {
            $typeName = $match[1];
            $length = (int) ($match[3] ?? 0);
            if (isset($match[5]))
            {
                $accuracy = (int) $match[5];
            }
            else
            {
                $accuracy = 0;
            }

            return true;
        }
        else
        {
            $typeName = '';
            $length = 0;
            $accuracy = 0;

            return false;
        }
    }

    /**
     * 渲染模版.
     *
     * @param string $template
     * @param array  $data
     *
     * @return string
     */
    private function renderTemplate($template, $data)
    {
        extract($data);
        ob_start();
        include __DIR__ . '/' . $template . '.tpl';

        return ob_get_clean();
    }

    /**
     * 数据库字段类型转PHP的字段类型.
     *
     * @param string $type
     *
     * @return string
     */
    private function dbFieldTypeToPhp($type)
    {
        $firstType = explode(' ', $type)[0];
        static $map = [
            'int'       => 'int',
            'smallint'  => 'int',
            'tinyint'   => 'int',
            'mediumint' => 'int',
            'bigint'    => 'int',
            'bit'       => 'boolean',
            'year'      => 'int',
            'double'    => 'float',
            'float'     => 'float',
            'decimal'   => 'float',
            'json'      => '\\' . \Imi\Util\LazyArrayObject::class,
        ];

        return $map[$firstType] ?? 'string';
    }

    /**
     * 获取创建表的 DDL.
     *
     * @param \Imi\Db\Query\Interfaces\IQuery $query
     * @param string                          $table
     *
     * @return string
     */
    public function getDDL(IQuery $query, string $table): string
    {
        $result = $query->execute('show create table `' . $table . '`');
        $sql = $result->get()['Create Table'] ?? '';
        $sql = preg_replace('/ AUTO_INCREMENT=\d+ /', ' ', $sql, 1);

        return $sql;
    }
}
