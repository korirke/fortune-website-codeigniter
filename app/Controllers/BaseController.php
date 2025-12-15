<?php

namespace App\Controllers;

use App\Helpers\DataTypeHelper;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Class BaseController
 *
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 * Extend this class in any new controllers:
 *     class Home extends BaseController
 *
 * For security be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * Instance of the main Request object.
     *
     * @var CLIRequest|IncomingRequest
     */
    protected $request;

    /**
     * An array of helpers to be loaded automatically upon
     * class instantiation. These helpers will be available
     * to all other controllers that extend BaseController.
     *
     * @var list<string>
     */
    protected $helpers = [];

    /**
     * Be sure to declare properties for any property fetch you initialized.
     * The creation of dynamic property is deprecated in PHP 8.2.
     */
    // protected $session;

    /**
     * @return void
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Do Not Edit This Line
        parent::initController($request, $response, $logger);

        // Preload any models, libraries, etc, here.

        // E.g.: $this->session = service('session');
    }

    /**
     * Normalize data for API response
     * Helper method that can be called manually if needed
     * 
     * @param mixed $data Data to normalize
     * @return mixed Normalized data
     */
    protected function normalizeData($data)
    {
        return DataTypeHelper::normalizeForApi($data);
    }

    /**
     * Build pagination response matching Node.js format
     * 
     * @param array $items Array of items
     * @param int $total Total count
     * @param int $page Current page
     * @param int $limit Items per page
     * @param string $message Optional message
     * @param string $itemsKey Key name for items array (default: 'items')
     * @return array Response array
     */
    protected function paginatedResponse(array $items, int $total, int $page, int $limit, string $message = 'Data retrieved successfully', string $itemsKey = 'items'): array
    {
        $totalPages = (int) ceil($total / $limit);
        
        return [
            'success' => true,
            'message' => $message,
            'data' => [
                $itemsKey => $items,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'totalPages' => $totalPages,
                    'hasNext' => $page < $totalPages,
                    'hasPrev' => $page > 1,
                ]
            ]
        ];
    }
}
