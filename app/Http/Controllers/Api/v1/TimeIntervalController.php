<?php

namespace App\Http\Controllers\Api\v1;

use App\Models\Screenshot;
use App\Models\TimeInterval;
use App\Rules\BetweenDate;
use App\User;
use Auth;
use Carbon\Carbon;
use Fico7489\Laravel\EloquentJoin\EloquentJoinBuilder;
use Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Validator;

/**
 * Class TimeIntervalController
 *
 * @package App\Http\Controllers\Api\v1
 */
class TimeIntervalController extends ItemController
{
    /**
     * @apiDefine WrongDateTimeFormatStartEndAt
     *
     * @apiError (Error 401) {String} Error Error
     *
     * @apiErrorExample {json} DateTime validation fail
     * {
     *   "error": "validation fail",
     *     "reason": {
     *     "start_at": [
     *       "The start at does not match the format Y-m-d\\TH:i:sP."
     *     ],
     *     "end_at": [
     *       "The end at does not match the format Y-m-d\\TH:i:sP."
     *     ]
     *   }
     * }
     */

    /**
     * @return string
     */
    public function getItemClass(): string
    {
        return TimeInterval::class;
    }


    /**
     * @param  int     $user_id
     * @param  string  $start_at
     *
     * @return array
     */
    public function getValidationRules(): array
    {
        return [
            'task_id' => 'exists:tasks,id|required',
            'user_id' => 'exists:users,id|required',
            'start_at' => 'date|required',
            'end_at' => 'date|required',
        ];
    }

    /**
     * @return array
     */
    public static function getControllerRules(): array
    {
        return [
            'index' => 'time-intervals.list',
            'count' => 'time-intervals.list',
            'create' => 'time-intervals.create',
            'bulkCreate' => 'time-intervals.bulk-create',
            'edit' => 'time-intervals.edit',
            'show' => 'time-intervals.show',
            'destroy' => 'time-intervals.remove',
            'bulkDestroy' => 'time-intervals.bulk-remove',
        ];
    }

    public function validateEndDate(array $intervalData): bool
    {
        $start_at = $intervalData['start_at'] ?? '';
        $end_at_rules = [];
        $timeOffset = 3600 /* one hour */;
        $beforeTimestamp = strtotime($start_at) + $timeOffset;
        $beforeDate = date(DATE_ATOM, $beforeTimestamp);
        $end_at_rules[] = new BetweenDate($start_at, $beforeDate);

        $validator = Validator::make(
            $intervalData,
            Filter::process(
                $this->getEventUniqueName('validation.item.create'),
                ['end_at' => $end_at_rules]
            )
        );

        return !$validator->fails();
    }

    /**
     * @param  Request  $request
     *
     * @return JsonResponse
     * @api            {post} /api/v1/time-intervals/create Create
     * @apiDescription Create Time Interval
     * @apiVersion     0.1.0
     * @apiName        CreateTimeInterval
     * @apiGroup       Time Interval
     *
     * @apiUse         UnauthorizedError
     *
     * @apiRequestExample {json} Request Example
     * {
     *   "task_id": 1,
     *   "user_id": 1,
     *   "start_at": "2013-04-12T16:40:00-04:00",
     *   "end_at": "2013-04-12T16:40:00-04:00"
     * }
     *
     * @apiSuccessExample {json} Answer Example
     * {
     *   "interval": {
     *     "id": 2251,
     *     "task_id": 1,
     *     "start_at": "2013-04-12 20:40:00",
     *     "end_at": "2013-04-12 20:40:00",
     *     "created_at": "2018-10-01 03:20:59",
     *     "updated_at": "2018-10-01 03:20:59",
     *     "count_mouse": 0,
     *     "count_keyboard": 0,
     *     "user_id": 1
     *   }
     * }
     *
     * @apiParam {Integer}  task_id   Task id
     * @apiParam {Integer}  user_id   User id
     * @apiParam {String}   start_at  Interval time start
     * @apiParam {String}   end_at    Interval time end
     *
     * @apiParam {Integer}  [count_mouse]     Mouse events count
     * @apiParam {Integer}  [count_keyboard]  Keyboard events count
     *
     * @apiUse         WrongDateTimeFormatStartEndAt
     *
     */
    public function create(Request $request): JsonResponse
    {
        $intervalData = [
            'task_id' => (int) $request->get('task_id'),
            'user_id' => (int) $request->get('user_id'),
            'start_at' => $request->get('start_at'),
            'end_at' => $request->get('end_at'),
            'count_mouse' => (int) $request->get('count_mouse') ?: 0,
            'count_keyboard' => (int) $request->get('count_keyboard') ?: 0,
        ];

        $validator = Validator::make(
            $intervalData,
            Filter::process(
                $this->getEventUniqueName('validation.item.create'),
                $this->getValidationRules()
            )
        );

        if ($validator->fails()) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.create'), [
                    'error' => 'validation fail',
                    'reason' => $validator->errors()
                ]),
                400
            );
        }

        //create time interval
        $intervalData['start_at'] = (new Carbon($intervalData['start_at']))->setTimezone('UTC')->toDateTimeString();
        $intervalData['end_at'] = (new Carbon($intervalData['end_at']))->setTimezone('UTC')->toDateTimeString();

        /*
         * TODO: я понятия не имею, зачем Александр возвращает 'success' ответы, но разбираться в этом как-то не
         * хочется. */

        // We'll check if there is an interval where current start_at are between this interval full range
       /* $lastInterval = TimeInterval::where(['user_id' => $intervalData['user_id']])->last();
        if ($lastInterval) {
            $carbonStartAt = Carbon::parse($intervalData['start_at']);
            if (Carbon::parse($lastInterval->start_at)->lt($carbonStartAt) &&
                Carbon::parse($lastInterval->end_at)->gt($carbonStartAt)) {
                return response()->json(
                    Filter::process($this->getEventUniqueName('answer.success.item.create'), [
                        'interval' => $lastInterval,
                    ]),
                    400
                );
            }
        }*/

       $existing = TimeInterval::where(['user_id' => $intervalData['user_id']])->where(function ($query) use ($intervalData) {
           $query->where('start_at', '<=', $intervalData['start_at']);
           $query->where('end_at', '>', $intervalData['start_at']);
       })->count();

        $timeInterval = Filter::process($this->getEventUniqueName('item.create'), new TimeInterval($intervalData));
        if (!$this->validateEndDate($intervalData)) {
            // If end date is not valid, return success without saving
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.success.item.create'), [
                    'interval' => $timeInterval,
                ]),
                400
            );
        }
        $timeInterval->save();

        //create screenshot
        if (isset($request->screenshot)) {
            $path = Filter::process($this->getEventUniqueName('request.item.create'),
                $request->screenshot->store('uploads/screenshots'));
            $screenshot = Image::make($path);
            $thumbnail = $screenshot->resize(280, null, function ($constraint) {
                $constraint->aspectRatio();
            });
            $thumbnailPath = str_replace('uploads/screenshots', 'uploads/screenshots/thumbs', $path);
            Storage::put($thumbnailPath, (string) $thumbnail->encode());

            $screenshotData = [
                'time_interval_id' => $timeInterval->id,
                'path' => $path,
                'thumbnail_path' => $thumbnailPath,
            ];

            $screenshot = Filter::process('item.create.screenshot', Screenshot::create($screenshotData));
        }

        return response()->json(
            Filter::process($this->getEventUniqueName('answer.success.item.create'), [
                'interval' => $timeInterval,
            ]),
            200
        );
    }

    /**
     * @param  Request  $request
     *
     * @return JsonResponse
     * @api            {post} /api/v1/time-intervals/bulk-create Bulk create
     * @apiDescription Create Time Intervals
     * @apiVersion     0.1.0
     * @apiName        BulkCreateTimeInterval
     * @apiGroup       Time Interval
     *
     * @apiParam {String}   intervals           Serialized array of time intervals
     * @apiParam {Integer}  intervals.task_id   Task id
     * @apiParam {Integer}  intervals.user_id   User id
     * @apiParam {String}   intervals.start_at  Interval time start
     * @apiParam {String}   intervals.end_at    Interval time end
     * @apiParam {Binary}   screenshots[index]  Screenshot file
     *
     * @apiSuccess {Object[]} messages                 Messages
     * @apiSuccess {Object}   messages.id              TimeInterval id
     * @apiSuccess {Object}   messages.user_id.        User id
     * @apiSuccess {Object}   messages.start_at        Start datetime
     * @apiSuccess {Object}   messages.end_at          End datetime
     * @apiSuccess {Object}   messages.created_at      TimeInterval
     * @apiSuccess {Object}   messages.deleted_at      TimeInterval
     *
     * @apiError (400)  {Object[]} messages         Messages
     * @apiError (400)  {String}   messages.error   Error title
     * @apiError (400)  {String}   messages.reason  Error reason
     * @apiError (400)  {String}   messages.code    Error code
     *
     * @apiUse         UnauthorizedError
     * @apiUse         WrongDateTimeFormatStartEndAt
     *
     */
    public function bulkCreate(Request $request): JsonResponse
    {
        $requestData = $request->all();
        $result = [];

        if (empty($requestData['intervals'])) {
            return response()->json(
                Filter::fire($this->getEventUniqueName('answer.error.item.create'), [
                    [
                        'error' => 'validation fail',
                        'reason' => 'intervals is required',
                    ]
                ]),
                400
            );
        }

        $intervals = json_decode($requestData['intervals'], true);
        foreach ($intervals as $index => $interval) {
            $intervalData = [
                'task_id' => (int) ($interval['task_id'] ?? 0),
                'user_id' => (int) ($interval['user_id'] ?? 0),
                'start_at' => $interval['start_at'] ?? '',
                'end_at' => $interval['end_at'] ?? '',
                'count_mouse' => (int) ($interval['count_mouse'] ?? 0),
                'count_keyboard' => (int) ($interval['count_keyboard'] ?? 0),
            ];

            $validator = Validator::make(
                $intervalData,
                Filter::process(
                    $this->getEventUniqueName('validation.item.create'),
                    $this->getValidationRules()
                )
            );

            if ($validator->fails()) {
                $result[] = [
                    'error' => 'validation fail',
                    'reason' => $validator->errors(),
                    'code' => 400
                ];
                continue;
            }

            //create time interval
            $intervalData['start_at'] = (new Carbon($intervalData['start_at']))->setTimezone('UTC')->toDateTimeString();
            $intervalData['end_at'] = (new Carbon($intervalData['end_at']))->setTimezone('UTC')->toDateTimeString();

            // If interval is already exists, do not create duplicate.
            $existing = TimeInterval::where([
                ['user_id', '=', $intervalData['user_id']],
                ['start_at', '=', $intervalData['start_at']],
                ['end_at', '=', $intervalData['end_at']],
            ])->first();
            if ($existing) {
                $result[] = $existing;
                continue;
            }

            $timeInterval = Filter::process($this->getEventUniqueName('item.create'), new TimeInterval($intervalData));
            if (!$this->validateEndDate($intervalData)) {
                // If end date is not valid, return success without saving
                $result[] = $timeInterval;
                continue;
            }
            $timeInterval->save();

            //create screenshot
            if (isset($request->screenshots[$index])) {
                $file = $request->screenshots[$index]->store('uploads/screenshots');
                $path = Filter::process($this->getEventUniqueName('request.item.create'), $file);
                $screenshot = Image::make($path);
                $thumbnail = $screenshot->resize(280, null, function ($constraint) {
                    $constraint->aspectRatio();
                });
                $thumbnailPath = str_replace('uploads/screenshots', 'uploads/screenshots/thumbs', $path);
                Storage::put($thumbnailPath, (string) $thumbnail->encode());

                $screenshotData = [
                    'time_interval_id' => $timeInterval->id,
                    'path' => $path,
                    'thumbnail_path' => $thumbnailPath,
                ];

                $screenshot = Filter::process('item.create.screenshot', Screenshot::create($screenshotData));
            }

            $result[] = $timeInterval;
        }

        return response()->json([
            'messages' => $result,
        ]);
    }

    /**
     * @return string
     */
    public function getEventUniqueNamePart(): string
    {
        return 'timeinterval';
    }

    /**
     * @param  Request  $request
     *
     * @return JsonResponse
     * @api            {post} /api/v1/time-intervals/list List
     * @apiDescription Get list of Time Intervals
     * @apiVersion     0.1.0
     * @apiName        GetTimeIntervalList
     * @apiGroup       Time Interval
     *
     * @apiParam {Integer}  [id]         `QueryParam` Time Interval id
     * @apiParam {Integer}  [task_id]    `QueryParam` Time Interval Task id
     * @apiParam {Integer}  [user_id]    `QueryParam` Time Interval User id
     * @apiParam {String}   [start_at]   `QueryParam` Interval Start DataTime
     * @apiParam {String}   [end_at]     `QueryParam` Interval End DataTime
     * @apiParam {String}   [created_at] `QueryParam` Time Interval Creation DateTime
     * @apiParam {String}   [updated_at] `QueryParam` Last Time Interval data update DataTime
     * @apiParam {String}   [deleted_at] `QueryParam` When Time Interval was deleted (null if not)
     *
     * @apiSuccess (200) {Object[]} TimeIntervalList Time Intervals
     *
     * @apiSuccessExample {json} Answer Example:
     * {
     *      {
     *          "id":1,
     *          "task_id":1,
     *          "start_at":"2006-06-20 15:54:40",
     *          "end_at":"2006-06-20 15:59:38",
     *          "created_at":"2018-10-15 05:54:39",
     *          "updated_at":"2018-10-15 05:54:39",
     *          "deleted_at":null,
     *          "count_mouse":42,
     *          "count_keyboard":43,
     *          "user_id":1
     *      },
     *      ...
     * }
     *
     * @apiUse         UnauthorizedError
     *
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->all();
        $request->get('project_id') ? $filters['task.project_id'] = $request->get('project_id') : false;

        $baseQuery = $this->applyQueryFilter(
            $this->getQuery(),
            $filters ?: []
        );

        $itemsQuery = Filter::process(
            $this->getEventUniqueName('answer.success.item.list.query.prepare'),
            $baseQuery
        );

        return response()->json(
            Filter::process(
                $this->getEventUniqueName('answer.success.item.list.result'),
                $itemsQuery->get()
            )
        );
    }

    /**
     * @api            {post} /api/v1/time-intervals/show Show
     * @apiDescription Show Time Interval
     * @apiVersion     0.1.0
     * @apiName        ShowTimeInterval
     * @apiGroup       Time Interval
     *
     * @apiParam {Integer}  id     Time Interval id
     *
     * @apiRequestExample {json} Request Example
     * {
     *   "id": 1
     * }
     *
     * @apiSuccess {Object}  object TimeInterval
     * @apiSuccess {Integer} object.id
     *
     * @apiSuccessExample {json} Answer Example
     * {
     *   "id": 1,
     *   "task_id": 1,
     *   "start_at": "2006-05-31 16:15:09",
     *   "end_at": "2006-05-31 16:20:07",
     *   "created_at": "2018-09-25 06:15:08",
     *   "updated_at": "2018-09-25 06:15:08",
     *   "deleted_at": null,
     *   "count_mouse": 88,
     *   "count_keyboard": 127,
     *   "user_id": 1
     * }
     *
     * @apiUse         UnauthorizedError
     */

    /**
     * @api            {post} /api/v1/time-intervals/edit Edit
     * @apiDescription Edit Time Interval
     * @apiVersion     0.1.0
     * @apiName        EditTimeInterval
     * @apiGroup       Time Interval
     *
     * @apiParam {Integer}  id           Time Interval id
     * @apiParam {Integer}  [user_id]    Time Interval User id
     * @apiParam {String}   [start_at]   Interval Start DataTime
     * @apiParam {String}   [end_at]     Interval End DataTime
     * @apiParam {String}   [created_at] Time Interval Creation DateTime
     * @apiParam {String}   [updated_at] Last Time Interval data update DataTime
     * @apiParam {String}   [deleted_at] When Time Interval was deleted (null if not)
     *
     * @apiSuccess {Object} res                 TimeInterval
     * @apiSuccess {Object} res.id              TimeInterval id
     * @apiSuccess {Object} res.user_id.        User id
     * @apiSuccess {Object} res.start_at        Start datetime
     * @apiSuccess {Object} res.end_at          End datetime
     * @apiSuccess {Object} res.created_at      TimeInterval
     * @apiSuccess {Object} res.deleted_at      TimeInterval
     *
     *
     * @apiSuccessExample {json} Answer example
     * {
     * "res":
     *   {
     *     "id":1,
     *     "task_id":1,
     *     "start_at":"2018-10-03 10:00:00",
     *     "end_at":"2018-10-03 10:00:00",
     *     "created_at":"2018-10-15 05:50:39",
     *     "updated_at":"2018-10-15 05:50:43",
     *     "deleted_at":null,
     *     "count_mouse":42,
     *     "count_keyboard":43,
     *     "user_id":1
     *   }
     * }
     *
     *
     * @apiUse         UnauthorizedError
     */
    public function edit(Request $request): JsonResponse
    {
        $requestData = Filter::process(
            $this->getEventUniqueName('request.item.edit'),
            $request->all()
        );

        $validationRules = $this->getValidationRules();
        $validationRules['id'] = ['required'];

        $validator = Validator::make(
            $requestData,
            Filter::process(
                $this->getEventUniqueName('validation.item.edit'),
                $validationRules
            )
        );

        if ($validator->fails()) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.edit'), [
                    'error' => 'Validation fail',
                    'reason' => $validator->errors()
                ]),
                400
            );
        }

        //create time interval
        $requestData['start_at'] = (new Carbon($requestData['start_at']))->setTimezone('UTC')->toDateTimeString();
        $requestData['end_at'] = (new Carbon($requestData['end_at']))->setTimezone('UTC')->toDateTimeString();

        if (!is_int($request->get('id'))) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.edit'), [
                    'error' => 'Invalid id',
                    'reason' => 'Id is not integer',
                ]),
                400
            );
        }

        /** @var Builder $itemsQuery */
        $itemsQuery = Filter::process(
            $this->getEventUniqueName('answer.success.item.query.prepare'),
            $this->applyQueryFilter(
                $this->getQuery()
            )
        );

        /** @var \Illuminate\Database\Eloquent\Model $item */
        $item = collect($itemsQuery->get())->first(function ($val, $key) use ($request) {
            return $val['id'] === $request->get('id');
        });

        if (!$item) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.edit'), [
                    'error' => 'Model fetch fail',
                    'reason' => 'Model not found',
                ]),
                400
            );
        }

        $item->fill($this->filterRequestData($requestData));
        if (!$this->validateEndDate($requestData)) {
            // If end date is not valid, return success without saving
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.success.item.edit'), [
                    'res' => $item,
                ]),
                200
            );
        }
        $item = Filter::process($this->getEventUniqueName('item.edit'), $item);
        $item->save();

        return response()->json(
            Filter::process($this->getEventUniqueName('answer.success.item.edit'), [
                'res' => $item,
            ])
        );
    }

    /**
     * @api            {delete, post} /api/v1/time-intervals/remove Destroy
     * @apiDescription Destroy Time Interval
     * @apiVersion     0.1.0
     * @apiName        DestroyTimeInterval
     * @apiGroup       Time Interval
     *
     * @apiParam {Integer}   id Time interval id
     *
     * @apiSuccess {String} message Message
     *
     * @apiSuccessExample {json} Answer Example
     * {
     *   "message":"Item has been removed"
     * }
     *
     * @apiUse         UnauthorizedError
     */

    /**
     * @param  Request  $request
     *
     * @return JsonResponse
     * @throws \Exception
     * @api            {delete, post} /api/v1/time-intervals/bulk-remove BulkDestroy
     * @apiDescription Multiple Destroy TimeInterval
     * @apiVersion     0.1.0
     * @apiName        BulkDestroyTimeInterval
     * @apiGroup       Time Interval
     *
     * @apiParam {Object[]}    array              Time Intervals
     * @apiParam {Object}      array.object       Time Interval
     * @apiParam {Integer}     array.object.id    Time Interval id
     *
     * @apiParamExample {json} Request Example
     * {
     *   "intervals": [
     *     {
     *       "id": "1"
     *     }
     *   ]
     * }
     *
     * @apiSuccess {Object[]} messages               Messages
     * @apiSuccess {Object}   message                Message
     * @apiSuccess {String}   message.message        Status
     *
     * @apiSuccessExample {json} Response Example
     * {
     *   "messages": [
     *     {
     *       "message": "Item has been removed"
     *     }
     *   ]
     * }
     *
     * @apiError (404)  {Object[]} messages                 Messages
     * @apiError (404)  {Object}   messages.message         Message
     * @apiError (404)  {String}   messages.message.error   Error title
     * @apiError (404)  {String}   messages.message.reason  Error reason
     *
     * @apiErrorExample (404) {json} Errors Response Example
     * {
     *   "messages": [
     *     {
     *       "error": "Item has not been removed",
     *       "reason": "Item not found"
     *     }
     *   ]
     * }
     *
     * @apiUse         UnauthorizedError
     *
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $requestData = Filter::process($this->getEventUniqueName('request.item.destroy'), $request->all());
        $result = [];

        if (empty($requestData['intervals'])) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.bulkEdit'), [
                    'error' => 'validation fail',
                    'reason' => 'intervals is empty',
                ]),
                400
            );
        }

        $intervals = $requestData['intervals'];
        if (!is_array($intervals)) {
            return response()->json(
                Filter::process($this->getEventUniqueName('answer.error.item.bulkEdit'), [
                    'error' => 'validation fail',
                    'reason' => 'intervals should be an array',
                ]),
                400
            );
        }

        foreach ($intervals as $interval) {
            /** @var Builder $itemsQuery */
            $itemsQuery = Filter::process(
                $this->getEventUniqueName('answer.success.item.query.prepare'),
                $this->applyQueryFilter(
                    $this->getQuery(),
                    $interval
                )
            );

            $validator = Validator::make(
                $interval,
                Filter::process(
                    $this->getEventUniqueName('validation.item.edit'),
                    ['id' => 'exists:time_intervals,id|required']
                )
            );

            if ($validator->fails()) {
                $result[] = [
                    'error' => 'Validation fail',
                    'reason' => $validator->errors(),
                    'code' => 400
                ];
                continue;
            }

            /** @var \Illuminate\Database\Eloquent\Model $item */
            $item = $itemsQuery->first();
            if ($item && $item->delete()) {
                $result[] = ['message' => 'Item has been removed'];
            } else {
                $result[] = [
                    'error' => 'Item has not been removed',
                    'reason' => 'Item not found'
                ];
            }
        }

        return response()->json(
            Filter::process($this->getEventUniqueName('answer.success.item.remove'), [
                'messages' => $result
            ])
        );
    }

    /**
     * @param  bool  $withRelations
     *
     * @return Builder
     */
    protected function getQuery($withRelations = true): Builder
    {
        /** @var User $user */
        $user = Auth::user();

        $query = parent::getQuery($withRelations);
        $full_access = $user->allowed('time-intervals', 'full_access');

        if ($full_access) {
            return $query;
        }

        $query->where(static function (EloquentJoinBuilder $query) use ($user) {
            $query->where('user_id', '=', $user->id);

            if ($user->allowed('projects', 'relations')) {
                $query->joinRelations('task.project');
                $query->orWhereHas('task.project', static function (EloquentJoinBuilder $query) use ($user) {
                    $query
                        ->select('id')
                        ->whereIn('id', $user->projects->map(static function ($project) {
                            return $project->id;
                        }))
                        ->limit(1);
                });
            }
        });

        return $query;
    }
}
