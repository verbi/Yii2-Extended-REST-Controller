<?php

namespace verbi\yii2ExtendedRestController;

use Yii;
use verbi\yii2Oauth2Server\filters\auth\TokenHttpBearerAuth;
use verbi\yii2Oauth2Server\filters\auth\TokenQueryParamAuth;
use yii\data\ActiveDataProvider;
use verbi\yii2Oauth2Server\filters\ErrorToExceptionFilter;
use verbi\yii2Oauth2Server\filters\auth\TokenCompositeAuth;
use \yii\helpers\Inflector;

/**
 * @author Philip Verbist <philip.verbist@gmail.com>
 * @link https://github.com/verbi/yii2-extended-activerecord/
 * @license https://opensource.org/licenses/GPL-3.0
 */
class ActiveController extends \yii\rest\ActiveController {
    use \verbi\yii2Helpers\traits\ControllerTrait;

    public $prepareDataProvider;
    protected $extraPatterns = [];
    protected $findModel;

    public function actions() {
        $actions = parent::actions();
        // customize the data provider preparation with the "prepareDataProvider()" method
        $actions['index']['prepareDataProvider'] = [$this, 'prepareDataProvider'];
        $actions['view']['checkAccess'] = [$this, 'checkAccess'];
        $actions['update']['checkAccess'] = [$this, 'checkAccess'];
        $actions['create']['checkAccess'] = [$this, 'checkAccess'];
        $actions['delete']['checkAccess'] = [$this, 'checkAccess'];
        return $actions;
    }

    public function getAvailableActions() {
        $functions = $this->getMethods();
        $actions = array_filter(
                $functions, function($var) {
            return preg_match('!action[A-Z0-9](.*)!', $var);
        }
        );
        array_walk(
                $actions, function(&$var) {
            $var = lcfirst(substr($var, strlen('action')));
        }
        );
        return $actions;
    }

    public function getExtraPatterns() {
        if (!$this->extraPatterns) {
            $this->extraPatterns = $this->generateExtraPatterns();
        }
        return $this->extraPatterns;
    }

    protected function generateExtraPatterns() {
        $patterns = [];
        $verbs = [];
        if ($this->hasMethod('verbs')) {
            $verbs = $this->verbs();
        }
        foreach ($this->getAvailableActions() as $action) {
            $prefix = '';
            if (isset($verbs[$action])) {
                $prefix = implode(',', $verbs[$action]) . ' ';
            }

            // also add params to patterns
            $actionFunction = 'action' . ucfirst($action);
            if ($this->hasMethod($actionFunction)) {
                $method = $this->getReflectionMethod($actionFunction);
                $parameters = $method->getParameters();
                array_walk(
                        $parameters, function( &$var ) {
                    $var = '<' . $var->name . '>';
                }
                );
                if (sizeof($parameters)) {
                    $patterns[$prefix . Inflector::camel2id($action) . '/' . implode('/', $parameters)] = Inflector::camel2id($action);
                }
            }
            $patterns[$prefix . Inflector::camel2id($action)] = Inflector::camel2id($action);
        }
        return $patterns;
    }

    protected function verbs() {
        $verbs = parent::verbs();
        /*
         * We want to override the default verbs, because otherwise OPTIONS don't work.
         * Somehow the Yii2 documentation didn't describe reality. 
         * This fixes one of those issues.
         */
        $verbs['options'] = ['OPTIONS'];
        foreach ($this->getBehaviors() as $behavior) {
            if ($behavior->hasMethod('verbs')) {
                $verbs = array_merge($verbs, $behavior->verbs());
            }
        }
        return $verbs;
    }

    public function prepareDataProvider() {
        // prepare and return a data provider for the "index" action
        if ($this->prepareDataProvider !== null) {
            return call_user_func($this->prepareDataProvider, $this);
        }
        /* @var $modelClass \yii\db\BaseActiveRecord */
        $modelClass = $this->modelClass;

        $identity = Yii::$app->user->identity;
        $user_id = $identity->id;
        $query = $modelClass::find();

        if ($search = Yii::$app->getRequest()->get('search')) {
            $model = new $modelClass();
            $attributes = $model->getAttributes();
            $andWhere = array('and');
            foreach (explode(' ', $search) as $term) {
                $where = array('or');
                foreach ($attributes as $attribute => $value) {
                    $where[] = ['like', $attribute, $term];
                }
                $andWhere[] = $where;
            }
            $query = $query->where($andWhere);
        }
        return new ActiveDataProvider([
            'query' => $query,
            'pagination' => [
                'pageSizeLimit' => [1, 20000]
            ],
        ]);
    }

    public function behaviors() {
        $behaviors = parent::behaviors();
        $behaviors[] = \verbi\yii2Helpers\behaviors\base\ComponentBehavior::className();
        $behaviors['authenticator'] = [
            'class' => TokenCompositeAuth::className(),
            'only' => [],
            'authMethods' => [
                TokenHttpBearerAuth::className(),
                TokenQueryParamAuth::className(),
            ],
        ];
        $behaviors['exceptionFilter'] = [
            'class' => ErrorToExceptionFilter::className()
        ];
        
        /*
         * The W3 spec for CORS preflight requests clearly states that user credentials should be excluded. 
         * There is a bug in Chrome and WebKit where OPTIONS requests returning a status of 401 still send 
         * the subsequent request.
         *
         * Firefox has a related bug filed that ends with a link to the W3 public webapps mailing list asking 
         * for the CORS spec to be changed to allow authentication headers to be sent on the OPTIONS request 
         * at the benefit of IIS users. Basically, they are waiting for those servers to be obsoleted.
         * 
         * How can I get the OPTIONS request to send and respond consistently?
         * 
         * Simply have the server (API in this example) respond to OPTIONS requests without requiring authentication. 
         */
        $behaviors['contentNegotiator']['formats']['application/json'] = isset($_GET['callback']) ? \yii\web\Response::FORMAT_JSONP : \yii\web\Response::FORMAT_JSON;
        $behaviors['contentNegotiator']['formats']['application/jsonp'] = \yii\web\Response::FORMAT_JSONP;

        if ($this->modelClass) {
            $modelClass = $this->modelClass;
            $model = new $modelClass;
            if ($model->hasMethod('addRestControllerBehaviors')) {
                $behaviors = array_merge(
                        $behaviors, $model->addRestControllerBehaviors()
                );
                return $behaviors;
            }
        }
        return $behaviors;
    }

    public function checkAccess($action, $model = null, $params = []) {
        parent::checkAccess($action, $model, $params);
        if ($model && !$model->checkAccess(Yii::$app->user->identity))
            throw new \yii\web\ForbiddenHttpException('You do not have access');
    }

    public function afterAction($action, $result) {
        $result = parent::afterAction($action, $result);

        if (Yii::$app->response->format == \yii\web\Response::FORMAT_JSONP) {
            if (isset($_GET['callback'])) {
                $result = array('callback' => $_GET['callback'], 'data' => $result);
            }
        }
        /*
         * CORS requires some headers in order for the requests to work. 
         * These are current working headers for the app.
         * 
         * We may want to move this to a better location, or maybe change 
         * the entire Header-logic.
         */
        Yii::$app->getResponse()->getHeaders()->set('Access-Control-Allow-Credentials', 'true');
        Yii::$app->getResponse()->getHeaders()->set('Access-Control-Allow-Origin', Yii::$app->request->getHeaders()->get('Origin'));
        Yii::$app->getResponse()->getHeaders()->set('Access-Control-Allow-Headers', 'Authorization');
        $headers = implode(',', array_keys(Yii::$app->response->getHeaders()->toArray()));
        Yii::$app->getResponse()->getHeaders()->set('Access-Control-Expose-Headers', $headers);
        return $result;
    }

    protected function findModel($id) {
        if ($this->findModel !== null) {
            return call_user_func($this->findModel, $id, $this);
        }
        /* @var $modelClass ActiveRecordInterface */
        $modelClass = $this->modelClass;
        $keys = $modelClass::primaryKey();
        if (count($keys) > 1) {
            $values = explode(',', $id);
            if (count($keys) === count($values)) {
                $model = $modelClass::findOne(array_combine($keys, $values));
            }
        } elseif ($id !== null) {
            $model = $modelClass::findOne($id);
        }
        if (isset($model)) {
            return $model;
        } else {
            throw new NotFoundHttpException("Object not found: $id");
        }
        return null;
    }
}
