<?php

namespace markhuot\CraftQL\Controllers;

use Craft;
use craft\web\Controller;
use craft\records\User;
use markhuot\CraftQL\CraftQL;
use markhuot\CraftQL\Models\Token;
use yii\web\ForbiddenHttpException;

class ApiController extends Controller
{
    protected $allowAnonymous = ['index'];

    private $graphQl;
    private $request;

    function __construct(
        $id,
        $module,
        \markhuot\CraftQL\Services\GraphQLService $graphQl,
        $config = []
    ) {
        parent::__construct($id, $module, $config);
        $this->graphQl = $graphQl;
    }

    /**
     * @inheritdoc
     */
    public function beforeAction($action)
    {
        // disable csrf
        $this->enableCsrfValidation = false;

        return parent::beforeAction($action);
    }

    function actionDebug() {
        $instance = \markhuot\CraftQL\CraftQL::getInstance();

        $oldMode = \Craft::$app->getView()->getTemplateMode();
        \Craft::$app->getView()->setTemplateMode(\craft\web\View::TEMPLATE_MODE_CP);
        $data = $this->getView()->renderPageTemplate('craftql/debug-input', [
            'uri' => $instance->getSettings()->uri,
        ]);
        \Craft::$app->getView()->setTemplateMode($oldMode);
        return $data;
    }

    function actionIndex()
    {
        $token = false;

        $authorization = Craft::$app->request->headers->get('authorization');
        preg_match('/^(?:b|B)earer\s+(?<tokenId>.+)/', $authorization, $matches);
        $token = Token::findId(@$matches['tokenId']);

        // @todo, check user permissions when PRO license

        $response = \Craft::$app->getResponse();
        if ($allowedOrigins = CraftQL::getInstance()->getSettings()->allowedOrigins) {
            if (is_string($allowedOrigins)) {
                $allowedOrigins = [$allowedOrigins];
            }
            $origin = \Craft::$app->getRequest()->headers->get('Origin');
            if (in_array($origin, $allowedOrigins) || in_array('*', $allowedOrigins)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
            }
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Allow-Headers', implode(', ', CraftQL::getInstance()->getSettings()->allowedHeaders));
        }
        $response->headers->set('Allow', implode(', ', CraftQL::getInstance()->getSettings()->verbs));

        if (\Craft::$app->getRequest()->isOptions) {
            return '';
        }

        if (!$token) {
            http_response_code(403);
            return $this->asJson([
                'errors' => [
                    ['message' => 'Not authorized']
                ]
            ]);
        }

        // assume we're passing in singular, non-batched queries
        $singular = true;

        Craft::trace('CraftQL: Parsing request');

        // parse the post request body for data
        $body = Craft::$app->request->getRawBody();
        $body = json_decode($body, true);

        // if we pass the query through a POST form field
        if (Craft::$app->request->isPost && Craft::$app->request->post('query')) {
            $input = Craft::$app->request->post('query');
            $variables = json_decode(Craft::$app->request->post('variables') ?: '{}', true);
        }

        // if we pass the query through a GET form field
        else if (Craft::$app->request->isGet && Craft::$app->request->get('query')) {
            $input = Craft::$app->request->get('query');
            $variables = json_decode(Craft::$app->request->get('variables') ?: '{}', true);
        }

        // if we pass the query through the request body by itself, not in a batch
        else if (!empty($body['query'])) {
            $input = $body['query'];
            $variables = @$body['variables'];
        }

        // if none of the above match, assume we're passing in a batched query
        else {
            $singular = false;
            $input = $body;
            $variables = null;
        }

        // if the query is a single query, convert it to a batch here so we can use
        // the same processing regarless of input. The results will get downgraded to
        // a single result later on
        if ($singular) {
            $input = [
                ['query' => $input, 'variables' => $variables]
            ];
        }
        Craft::trace('CraftQL: Parsing request complete');

        Craft::trace('CraftQL: Bootstrapping');
        $this->graphQl->bootstrap();
        Craft::trace('CraftQL: Bootstrapping complete');

        Craft::trace('CraftQL: Fetching schema');
        $schema = $this->graphQl->getSchema($token);
        Craft::trace('CraftQL: Schema built');

        Craft::trace('CraftQL: Executing query');
        $result = $this->graphQl->execute($schema, $input, $variables);
        if ($singular) { $result = $result[0]; }
        Craft::trace('CraftQL: Execution complete');

        $customHeaders = CraftQL::getInstance()->getSettings()->headers ?: [];
        foreach ($customHeaders as $key => $value) {
            if (is_callable($value)) {
                $value = $value($schema, $input, $variables, $result);
            }
            $response = \Craft::$app->getResponse();
            $response->headers->add($key, $value);
        }

        if (!!Craft::$app->request->post('debug')) {
            $response = \Yii::$app->getResponse();
            $response->format = \craft\web\Response::FORMAT_HTML;

            $oldMode = \Craft::$app->getView()->getTemplateMode();
            \Craft::$app->getView()->setTemplateMode(\craft\web\View::TEMPLATE_MODE_CP);
            $response->data = $this->getView()->renderPageTemplate('craftql/debug-response', ['json' => json_encode($result)]);
            \Craft::$app->getView()->setTemplateMode($oldMode);

            return $response;
        }

        // You must set the header to JSON, otherwise Craft will see HTML and try to insert
        // javascript at the bottom to run pending tasks
        $response = \Craft::$app->getResponse();
        $response->headers->add('Content-Type', 'application/json; charset=UTF-8');

        return $this->asJson($result);
    }
}
