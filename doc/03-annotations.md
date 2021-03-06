Annotations
===========

## @Prefix
Define this property to customize the prefix used for the key name that will contain the hash of the object's data. If
you omit this annotation, the class name will be used.

## @Id
Each class must have One `@Id` annotated property. The value of this property will be used when creating the indexes.

Note: It's up to you to ensure that this is some unique value for each of your objects. Otherwise, you will see that
saving objects with the same id will overwrite the entries in the indexes and the key containing the data for the object.

## @Field
Any properties you want to be stored in redis must be annotated with this annotation. You may specify the type as one of
the following:

    string
    integer
    double
    boolean
    date
    collection
    hash

The `collection` type denotes a numeric indexed array.
The `hash` type denotes an associative array.

If you wish to customize the name of the field, to may do so.

    /**
     * @Field(name="some_property", type="string")
     */
     protected $someProperty;

## @Index
Define this annotation on fields by which you want to be able to search.
The `@Index` annotation indicates that a key will be created with the name and value of this property as the key name.
The value of the `@Id` will be inserted into the key's set. Read more about redis sets [here][1].

## @SortedIndex
Define this annotation on properties by which you want to perform range queries.

## @Date
A subset of `@SortedIndex`, define this annotation on properties that you want to perform date range queries
The `@Date` annotation indicates that a key will be created with the name and value of this property as the key name, 
and the value of `@Id` annotated property will be inserted into the keys sorted set. This property's value will be
converted to a timestamp and used as the score by which the object will be sorted. Read more about redis sorted sets
[here][2].

[1]: http://redis.io/topics/data-types#sets
[2]: http://redis.io/topics/data-types#sorted-sets
