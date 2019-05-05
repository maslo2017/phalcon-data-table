<?php

namespace Masvp\DataTable;

use Exception;

/**
 * Class MySqlCreater
 *
 * A class that allows you to simplify interaction with the
 * bootstrap-table in Phalcon
 *
 * <code>
 * $model   = new ResetPasswords();
 * $creator = new MySqlCreater($model, [
 *      'columns'    => 'id,usersId,ipAddress,type,createdAt',
 *      's_columns'  => 'ipAddress',
 *      'conditions' => ['usersId = :user_id_con:'],
 *      'bind'       => ['user_id_con' => $user->id],
 *      'data'       => $this->request->getPost(),
 * ]);
 *
 * return $this->response->setJsonContent($creator->getResult());
 * </code>
 */
class MySqlCreater
{
    private $_result = array();
    private $_model;
    private $_config;

    /**
     * Masvp\DataTable\MySqlCreater constructor.
     *
     * @param \Phalcon\Mvc\Model $model             phalcon model
     * @param array              $configuration     configuration array
     * $configuration array:
     *      // columns - columns access
     *      // example: "id,usersId,ipAddress,type,createdAt"
     *      // example: ["id","usersId","ipAddress","type","createdAt"]
     *      'columns' => [array|string]
     *
     *      // s_columns - columns access for filters
     *      // example: "ipAddress,type"
     *      // example: ["ipAddress","type"]
     *      's_columns' => [array|string]
     *
     *      // search_column - column for search ('%'.value.'%') - data-search="true"
     *      // example: "ipAddress"
     *      'search_column' => [string]
     *
     *      // conditions (Can not be used without 'bind')
     *      // example: ['usersId = :user_id:']
     *      'conditions' => [array]
     *
     *      // bind - bind values for conditions (Can not be used without 'conditions')
     *      // example: ['user_id' => $user->id]
     *      'bind'
     *
     *      // data - request for bootstrap table
     *      // example: $this->request->getPost()
     *      'data' => [array]
     *
     *      // max_rows - maximum rows of query (limit)
     *      // example: 1000
     *      'max_rows' => [int]
     */
    public function __construct(\Phalcon\Mvc\Model $model, array $configuration) {
        try {
            if(!isset($configuration['columns']))
                throw new MySqlCreaterException('No columns');

            if(gettype($configuration['columns']) == 'string')
            {
                $configuration['columns'] = explode(',', $configuration['columns']);
            }
            if(isset($configuration['s_columns']))
            {
                if(gettype($configuration['s_columns']) == 'string')
                    $configuration['s_columns'] = explode(',', $configuration['s_columns']);
            }

            if(!isset($configuration['data']) || !count($configuration['data']))
                throw new MySqlCreaterException('No data array');

            $columns = $configuration['columns'];

            $mt = $model->getModelsMetaData();
            $model_rows = $mt->getAttributes($model);

            foreach($columns as $col)
            {
                if(array_search($col, $model_rows) === false)
                    throw new MySqlCreaterException($col.' is no '.$model->getSource().' model colums: '.json_encode($model_rows));
            }

            $this->_model = $model;
            $this->_config = $configuration;

        } catch(MySqlCreaterException $ex) {
            $this->_setResultError($ex->getMessage());
        }

    }

    /**
     * get result response array
     *
     * @return array
     */
    public function getResult()
    {
        // check errors
        if($this->_result['error'])
            return $this->_result;

        // read data array
        $data = $this->_config['data'];
        $search = isset($data['search']) ? $data['search'] : false;
        if(empty(trim($search)))
            $search = false;
        $sort   = isset($data['sort']) ? $data['sort'] : false;
        $order  = isset($data['order']) ? $data['order'] : false;
        $offset = isset($data['offset']) ? $data['offset'] : false;
        $limit  = isset($data['limit']) ? $data['limit'] : false;
        $filters = isset($data['filter']) ? $data['filter'] : false;
        if(empty(trim($filters)))
            $filters = false;
        if($filters)
            $filters = json_decode($filters);

        // get \Phalcon\Mvc\Model\Criteria
        $query = $this->_model->query();
        // set columns
        $query->columns(join(',', $this->_config['columns']));

        $params = [];
        $binds = [];
        try {
            // set search
            if($search)
            {
                if(isset($data['search_column']))
                {
                    $binds['serch_value'] = '%'.$search.'%';
                    $params[] = $data['search_column'].' LIKE :serch_value:';
                }
            }

            // set filters
            if($filters) {
                foreach($filters as $key => $value) {
                    if(intval($value) === 0 || !empty($value)) {
                        if($value != '<<all>>')
                        {
                            if(array_search($key, $this->_config['s_columns']) === false)
                                throw new MySqlCreaterException($key.' filter column is no access model columns');

                            $binds[$key.'A'] = $value;
                            $params[] = $key.' LIKE :'.$key.'A:';
                        }
                    }
                }
            }

            // set conditions
            if(isset($this->_config['conditions']) && isset($this->_config['bind']))
            {
                if(count($this->_config['conditions']) != count($this->_config['bind']))
                    throw new MySqlCreaterException('Count condition != count bind');

                foreach($this->_config['conditions'] as $con)
                {
                    $params[] = $con;
                }
                foreach($this->_config['bind'] as $key => $value)
                {
                    $binds[$key] = $value;
                }
            }

            // set where
            $where_result = implode(' AND ', $params);
            if(count($params) && count($binds))
                $query->where($where_result, $binds);

            // set order
            if($sort && $order)
            {
                if(strtolower($order) != 'asc' && strtolower($order) != 'desc')
                    throw new MySqlCreaterException($order.' is not access ("asc" and "desc" orders)');

                if(array_search($sort, $this->_config['columns']) === false)
                    throw new MySqlCreaterException($sort.' sort column is no access model columns');

                $query->orderBy($sort.' '.$order);
            }

            // set limit
            if($limit)
            {
                if(isset($this->_config['max_rows']))
                {
                    if($limit > $this->_config['max_rows'])
                        $limit = $this->_config['max_rows'];
                }
                if($offset)
                    $query->limit($limit, $offset);
                else
                    $query->limit($limit);
            }
            else
            {
                if(isset($this->_config['max_rows']))
                {
                    $query->limit($this->_config['max_rows']);
                }
            }

            // get result
            $ex_result = $query->execute();

            // get total
            $total = 0;
            if($search || $filters) {
                $total = $this->_model::count(
                    [
                        $where_result,
                        'bind' => $binds
                    ]
                );
            }

            // get count
            if(isset($this->_config['conditions']) && isset($this->_config['bind'])) {
                $params2 = [];
                $binds2 = [];
                foreach($this->_config['conditions'] as $con) {
                    $params2[] = $con;
                }
                foreach($this->_config['bind'] as $key => $value) {
                    $binds2[$key] = $value;
                }

                $count = $this->_model::count(
                    [
                        implode(' AND ', $params2),
                        'bind' => $binds2
                    ]
                );
            } else {
                $count = $this->_model::count();
            }

            // test total
            if($total <= 0)
                $total = $count;

            // set result data
            $this->_result['total']            = $total;
            $this->_result['totalNotFiltered'] = $count;
            $this->_result['rows']             = $ex_result->toArray();

        } catch(MySqlCreaterException $ex) {
            $this->_setResultError($ex->getMessage());
        }

        return $this->_result;
    }

    /**
     * set result errors
     *
     * @param $message
     * @return bool
     */
    private function _setResultError($message)
    {
        $this->_result['error'][] = $message;
        return false;
    }
}

/**
 * Class MySqlCreaterException
 *
 * @package Masvp\DataTable
 */
class MySqlCreaterException extends Exception
{

}